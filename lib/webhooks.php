<?php

declare(strict_types=1);

/**
 * A carrier's status board in Discord.
 *
 * One message per webhook, edited in place, showing where the carrier is and
 * what state it is in. Never a stream of posts: a channel following an active
 * carrier would fill with one-line messages and bury the thing worth reading.
 *
 * That is why any of this keeps message ids. A webhook POST normally answers
 * `204 No Content` and tells you nothing about what it just created; posting
 * to `...?wait=true` returns the message object, and its `id` is what later
 * `PATCH .../messages/{id}` calls need. Discord sets no time limit on editing
 * a webhook's own message, but a webhook can only touch messages it sent.
 *
 * Nothing is delivered inside the request that caused it. See fc_webhook_flush.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// fc_carrier_link and fc_docking_label live here. Both files define functions
// and print nothing, so this is safe from an upload with no page around it.
require_once __DIR__ . '/render.php';

/**
 * The only URLs we will ever make a server-side request to.
 *
 * This is a security boundary, not tidiness. The URL is supplied by whoever
 * owns the carrier and is then fetched *by the server*, which is the classic
 * shape of a request forgery: without this, "webhook" is a form that asks the
 * host to POST attacker-chosen JSON to an attacker-chosen address, including
 * things only reachable from inside the network. Anchored at both ends, https
 * only, and redirects are refused at the curl level so a 302 cannot walk it
 * somewhere else afterwards.
 */
const FC_DISCORD_URL_RE = '~^https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d{1,2})?/webhooks/\d{5,25}/[A-Za-z0-9_-]{20,120}$~';

/** Discord answers in well under a second; a hung call must not hold a worker. */
const FC_DISCORD_TIMEOUT = 8;

/** Consecutive failures before a webhook is switched off and left for its owner. */
const FC_WEBHOOK_MAX_FAILS = 10;

/** Sends attempted per flush. Discord allows about five a second per webhook. */
const FC_WEBHOOK_FLUSH_LIMIT = 12;

/** Tritium at or below this is worth flagging on the board. */
const FC_WEBHOOK_LOW_FUEL = 150;

function fc_webhook_url_ok(string $url): bool
{
    return preg_match(FC_DISCORD_URL_RE, $url) === 1;
}

/**
 * `https://discord.com/api/webhooks/123456789/abcdef...` → `…/1234567…/abcd…`
 *
 * The token half of the URL is the whole of its authority -- anyone holding it
 * can post to that channel -- so the settings page shows this instead.
 */
function fc_webhook_mask(string $url): string
{
    if (preg_match('~/webhooks/(\d+)/(.+)$~', $url, $m) !== 1) {
        return '(hidden)';
    }
    return '…/' . substr($m[1], 0, 7) . '…/' . substr($m[2], 0, 4) . '…';
}

/** Stop game-supplied text turning into bold, links or pings in a channel. */
function fc_discord_escape(?string $text): string
{
    $text = (string) $text;
    $text = preg_replace('/[\\\\*_~`|>]/', '\\\\$0', $text) ?? $text;
    // @everyone and @here are handled by allowed_mentions as well, but a
    // zero-width break costs nothing and survives being quoted elsewhere.
    return str_replace('@', '@' . "\u{200b}", $text);
}

// ---------------------------------------------------------------------------
// Queueing
// ---------------------------------------------------------------------------

function fc_webhook_enqueue(int $webhookId, string $kind, array $payload, string $dedupeHash): void
{
    // Never ping. A carrier's message of the day is free text the owner typed
    // and the board reproduces it; allowed_mentions is what stops "@everyone"
    // in it becoming an actual @everyone in somebody's Discord.
    $payload['allowed_mentions'] = ['parse' => []];

    fc_exec(
        'INSERT IGNORE INTO fc_webhook_queue (webhook_id, kind, payload, dedupe_hash, next_attempt_at, created_at)
         VALUES (:wid, :kind, :payload, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [
            'wid' => $webhookId,
            'kind' => $kind,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'hash' => $dedupeHash,
        ],
    );
}

function fc_webhook_carrier_title(array $carrier): string
{
    $name = fc_discord_escape($carrier['name'] ?? null);
    $callsign = $carrier['callsign'] ?? null;
    if ($name === '' && $callsign === null) {
        return 'Fleet carrier';
    }
    if ($name === '') {
        return (string) $callsign;
    }
    return $callsign === null ? $name : $name . ' (' . $callsign . ')';
}

// ---------------------------------------------------------------------------
// Turning events into notices
// ---------------------------------------------------------------------------

function fc_webhook_board_refresh(int $carrierId): void
{
    $hooks = fc_all(
        'SELECT * FROM fc_webhooks WHERE carrier_id = :cid AND enabled = 1 AND board_enabled = 1',
        ['cid' => $carrierId],
    );
    if ($hooks === []) {
        return;
    }
    $carrier = fc_carrier($carrierId);
    if ($carrier === null) {
        return;
    }

    foreach ($hooks as $hook) {
        $embed = fc_webhook_board_embed($carrier, (int) $hook['show_finance'] === 1);
        $payload = ['embeds' => [$embed]];

        // Hash what the board *says*, not when it was built. The embed carries
        // an "updated" timestamp of now, so hashing the payload whole would
        // differ on every upload and this check would never once suppress a
        // request -- an edit costs Discord a call whether or not the board
        // actually changed.
        $stable = $embed;
        unset($stable['timestamp'], $stable['footer']);
        $hash = sha1(json_encode($stable));
        if ($hash === $hook['board_hash']) {
            continue;
        }
        fc_exec('UPDATE fc_webhooks SET board_hash = :h WHERE id = :id', ['h' => $hash, 'id' => $hook['id']]);
        fc_webhook_enqueue((int) $hook['id'], 'board', $payload, sha1($hook['id'] . '|board|' . $hash));
    }
}

/**
 * What the board says.
 *
 * Laid out for the glance it actually gets. The two things anyone opens the
 * channel for -- where the carrier is, and whether it is about to move -- are
 * the description, so they read as sentences at the top. Everything else is
 * inline fields, deliberately in groups of three, because Discord lays inline
 * fields three to a row and any other number leaves a ragged half-row.
 */
function fc_webhook_board_embed(array $carrier, bool $withFinance): array
{
    // --- where it is, and where it is going ------------------------------
    $system = fc_discord_escape($carrier['system'] ?? null);
    $body = fc_discord_escape($carrier['body'] ?? null);
    $lines = [];
    $lines[] = $system === ''
        ? '📍 Position unknown'
        : '📍 **' . $system . '**' . ($body === '' ? '' : ' · ' . $body);

    $next = fc_one(
        "SELECT * FROM fc_jumps
          WHERE carrier_id = :cid AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['cid' => $carrier['id']],
    );
    if ($next !== null) {
        $at = strtotime((string) $next['departure_time'] . ' UTC');
        // DepartureTime is when the carrier *arrives*, not when it leaves.
        $lines[] = '🚀 Jumping to **' . fc_discord_escape((string) ($next['system'] ?? '?'))
            . '** — arrives <t:' . $at . ':R>';
    }

    $motd = trim((string) ($carrier['motd'] ?? ''));
    if ($motd !== '') {
        $lines[] = '';
        $lines[] = '> ' . str_replace("\n", "\n> ", fc_discord_escape($motd));
    }

    // --- the row of three -------------------------------------------------
    $fuel = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
    $fields = [
        [
            'name' => 'Docking',
            'value' => fc_docking_label($carrier['docking_access']),
            'inline' => true,
        ],
        [
            'name' => 'Tritium',
            'value' => $fuel === null
                ? '—'
                : fc_num($fuel) . ' t' . ($fuel <= FC_WEBHOOK_LOW_FUEL ? ' ⚠️' : ''),
            'inline' => true,
        ],
        [
            'name' => 'Free space',
            'value' => $carrier['space_free'] === null ? '—' : fc_num((int) $carrier['space_free']) . ' t',
            'inline' => true,
        ],
    ];

    // --- and a second row, only when finance is being shown ---------------
    if ($withFinance && $carrier['balance'] !== null) {
        $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
        $upkeep = fc_upkeep($crew, $carrier);
        $solvency = fc_solvency($upkeep, (int) $carrier['balance']);
        $span = fc_weeks_span($solvency['weeks']);

        $fields[] = ['name' => 'Balance', 'value' => fc_cr((int) $carrier['balance']) . ' cr', 'inline' => true];
        $fields[] = ['name' => 'Upkeep', 'value' => fc_cr($upkeep['total']) . ' cr/wk', 'inline' => true];
        $fields[] = [
            'name' => 'Covered for',
            'value' => $span === null ? '—' : $span . (($solvency['weeks'] ?? 99) < 2 ? ' ⚠️' : ''),
            'inline' => true,
        ];
    }

    return [
        'title' => fc_webhook_carrier_title($carrier),
        'url' => fc_carrier_link($carrier),
        'description' => implode("\n", $lines),
        'color' => ($fuel !== null && $fuel <= FC_WEBHOOK_LOW_FUEL) ? 0xf59e0b : 0x38bdf8,
        'fields' => $fields,
        'footer' => ['text' => 'Carrier Ops'],
        'timestamp' => gmdate('c'),
    ];
}

// ---------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------

/**
 * Send the response, then deliver.
 *
 * fastcgi_finish_request hands the finished page to nginx and lets PHP carry
 * on, so the uploader waits on our work and not on Discord's. Where it does
 * not exist the flush still happens, just inside the request -- correct, only
 * slower, and the queue means nothing is lost either way.
 */
function fc_webhook_flush_after_response(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            fc_webhook_flush();
        } catch (Throwable $e) {
            // Nothing is watching this far past the response; the queue keeps
            // the work and the next upload will try again.
            error_log('fc: webhook flush failed: ' . $e->getMessage());
        }
    });
}

/**
 * Deliver what is due, and reschedule what is not yet deliverable.
 *
 * Failures are separated by what they mean. A 429 is Discord pacing us and
 * says exactly how long to wait. A 401/403/404 means the webhook has been
 * deleted at the far end and will never work again, so the row is switched off
 * rather than retried forever. Anything else is treated as temporary.
 */
function fc_webhook_flush(): int
{
    $due = fc_all(
        'SELECT q.*, w.url, w.board_message_id, w.enabled
           FROM fc_webhook_queue q
           JOIN fc_webhooks w ON w.id = q.webhook_id
          WHERE q.next_attempt_at <= UTC_TIMESTAMP()
          ORDER BY q.id ASC
          LIMIT ' . FC_WEBHOOK_FLUSH_LIMIT,
    );

    $sent = 0;
    foreach ($due as $item) {
        // Disabled between queueing and now: drop the backlog rather than
        // deliver it late when the owner has already said no.
        if ((int) $item['enabled'] !== 1) {
            fc_exec('DELETE FROM fc_webhook_queue WHERE id = :id', ['id' => $item['id']]);
            continue;
        }

        $payload = json_decode((string) $item['payload'], true);
        if (!is_array($payload)) {
            fc_exec('DELETE FROM fc_webhook_queue WHERE id = :id', ['id' => $item['id']]);
            continue;
        }

        $result = $item['kind'] === 'board'
            ? fc_discord_board_send((int) $item['webhook_id'], (string) $item['url'], $item['board_message_id'], $payload)
            : fc_discord_send((string) $item['url'], $payload);

        if ($result['ok']) {
            fc_exec('DELETE FROM fc_webhook_queue WHERE id = :id', ['id' => $item['id']]);
            fc_exec(
                'UPDATE fc_webhooks SET last_sent_at = UTC_TIMESTAMP(), fail_count = 0, last_error = NULL WHERE id = :id',
                ['id' => $item['webhook_id']],
            );
            $sent++;
            continue;
        }

        if ($result['gone']) {
            fc_exec(
                'UPDATE fc_webhooks SET enabled = 0, last_error = :e WHERE id = :id',
                ['e' => mb_substr('Removed at Discord: ' . $result['error'], 0, 255), 'id' => $item['webhook_id']],
            );
            fc_exec('DELETE FROM fc_webhook_queue WHERE webhook_id = :wid', ['wid' => $item['webhook_id']]);
            continue;
        }

        $attempts = (int) $item['attempts'] + 1;
        if ($attempts >= FC_WEBHOOK_MAX_FAILS) {
            fc_exec('DELETE FROM fc_webhook_queue WHERE id = :id', ['id' => $item['id']]);
        } else {
            // Discord's own retry_after when it gave one, otherwise back off.
            $delay = $result['retry_after'] > 0 ? $result['retry_after'] : min(3600, 30 * (2 ** ($attempts - 1)));
            fc_exec(
                'UPDATE fc_webhook_queue
                    SET attempts = :a, last_error = :e,
                        next_attempt_at = (UTC_TIMESTAMP() + INTERVAL :d SECOND)
                  WHERE id = :id',
                ['a' => $attempts, 'e' => mb_substr($result['error'], 0, 255), 'd' => $delay, 'id' => $item['id']],
            );
        }

        fc_exec(
            'UPDATE fc_webhooks SET fail_count = fail_count + 1, last_error = :e WHERE id = :id',
            ['e' => mb_substr($result['error'], 0, 255), 'id' => $item['webhook_id']],
        );
    }

    // A webhook that has failed this consistently is not going to start
    // working on its own; leave it off with the reason recorded.
    fc_exec(
        'UPDATE fc_webhooks SET enabled = 0 WHERE enabled = 1 AND fail_count >= :n',
        ['n' => FC_WEBHOOK_MAX_FAILS],
    );

    return $sent;
}

/**
 * Post the board, or edit the one already there.
 *
 * The first send has to use `?wait=true` -- a plain POST returns 204 with no
 * body, and without the message id in the response there is nothing to edit
 * afterwards. If the stored message has since been deleted by hand, Discord
 * answers 404 and we post a fresh one rather than giving up on the board.
 */
function fc_discord_board_send(int $webhookId, string $url, ?string $messageId, array $payload): array
{
    if ($messageId !== null) {
        $result = fc_discord_send($url . '/messages/' . rawurlencode($messageId), $payload, 'PATCH');
        if ($result['ok']) {
            return $result;
        }
        if ($result['status'] !== 404) {
            return $result;
        }
        // Deleted at the far end. Forget it and fall through to a new post.
        fc_exec('UPDATE fc_webhooks SET board_message_id = NULL WHERE id = :id', ['id' => $webhookId]);
    }

    $result = fc_discord_send($url . '?wait=true', $payload);
    if ($result['ok'] && ($result['message_id'] ?? null) !== null) {
        fc_exec(
            'UPDATE fc_webhooks SET board_message_id = :m WHERE id = :id',
            ['m' => $result['message_id'], 'id' => $webhookId],
        );
    }
    return $result;
}

/**
 * One request to Discord.
 *
 * @return array{ok:bool,status:int,error:string,retry_after:int,gone:bool,message_id:?string}
 */
function fc_discord_send(string $url, array $payload, string $method = 'POST'): array
{
    $fail = static fn(string $error, int $status = 0, int $retry = 0, bool $gone = false): array => [
        'ok' => false, 'status' => $status, 'error' => $error,
        'retry_after' => $retry, 'gone' => $gone, 'message_id' => null,
    ];

    // The base is re-checked here and not only at the point it was saved: this
    // is the function that actually makes the request, and it is the only place
    // that can promise the request goes where it is supposed to.
    $base = preg_replace('~[?/](?:wait=true|messages/.*)$~', '', $url) ?? $url;
    if (!fc_webhook_url_ok($base)) {
        return $fail('Not a Discord webhook address.', 0, 0, true);
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => FC_DISCORD_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        // A redirect off discord.com would defeat the check above.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'CarrierOps (+' . fc_base_url() . '/fc/)',
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return $fail($curlError === '' ? 'Connection failed.' : $curlError);
    }

    if ($status === 429) {
        $decoded = json_decode((string) $response, true);
        $retry = (int) ceil((float) ($decoded['retry_after'] ?? 5));
        return $fail('Rate limited by Discord.', $status, max(1, $retry));
    }

    // 401/403 is a revoked token, 404 a deleted webhook. 400 means the payload
    // itself is wrong, which retrying cannot fix either.
    if (in_array($status, [400, 401, 403, 404], true)) {
        return $fail('Discord returned ' . $status . '.', $status, 0, $status !== 400);
    }

    if ($status < 200 || $status >= 300) {
        return $fail('Discord returned ' . $status . '.', $status);
    }

    $messageId = null;
    $decoded = json_decode((string) $response, true);
    if (is_array($decoded) && isset($decoded['id']) && is_scalar($decoded['id'])) {
        $messageId = (string) $decoded['id'];
    }

    return ['ok' => true, 'status' => $status, 'error' => '', 'retry_after' => 0, 'gone' => false, 'message_id' => $messageId];
}

// ---------------------------------------------------------------------------
// Owner actions
// ---------------------------------------------------------------------------

/**
 * Handle one of the webhook forms on the Manage tab.
 *
 * The caller has already established that the signed-in user owns $carrier and
 * that the CSRF token is good. Every lookup here is still scoped to the
 * carrier, so a guessed id from another carrier's list finds nothing.
 */
function fc_handle_webhook_post(string $action, array $carrier): void
{
    $carrierId = (int) $carrier['id'];

    $hookById = static function (mixed $id) use ($carrierId): ?array {
        return fc_one(
            'SELECT * FROM fc_webhooks WHERE id = :id AND carrier_id = :cid',
            ['id' => (int) $id, 'cid' => $carrierId],
        );
    };

    if ($action === 'webhook_add') {
        $url = trim((string) ($_POST['url'] ?? ''));
        if (!fc_webhook_url_ok($url)) {
            fc_flash('That is not a Discord webhook URL. Copy it from the channel’s Integrations settings — '
                . 'it starts https://discord.com/api/webhooks/', 'err');
            return;
        }
        if (fc_one('SELECT id FROM fc_webhooks WHERE carrier_id = :cid AND url = :u', ['cid' => $carrierId, 'u' => $url]) !== null) {
            fc_flash('That webhook is already attached to this carrier.', 'err');
            return;
        }
        // Six is well past what anyone needs and keeps one carrier's uploads
        // from turning into an unbounded fan-out of outbound requests.
        $count = (int) (fc_one('SELECT COUNT(*) AS n FROM fc_webhooks WHERE carrier_id = :cid', ['cid' => $carrierId])['n'] ?? 0);
        if ($count >= 6) {
            fc_flash('This carrier already has six webhooks. Remove one first.', 'err');
            return;
        }

        fc_exec(
            'INSERT INTO fc_webhooks (carrier_id, created_by, label, url, show_finance, board_enabled, created_at)
             VALUES (:cid, :uid, :label, :url, :fin, :board, UTC_TIMESTAMP())',
            [
                'cid' => $carrierId,
                'uid' => (int) ($carrier['owner_user_id'] ?? 0) ?: null,
                'label' => mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 64) ?: null,
                'url' => $url,
                'fin' => isset($_POST['show_finance']) ? 1 : 0,
                // The board is the only thing a webhook posts now, so it is
                // always on; the column stays for the sake of older rows.
                'board' => 1,
            ],
        );
        fc_webhook_board_refresh($carrierId);
        fc_webhook_flush_after_response();
        fc_flash('Webhook added.');
        return;
    }

    $hook = $hookById($_POST['webhook_id'] ?? 0);
    if ($hook === null) {
        fc_flash('No such webhook on this carrier.', 'err');
        return;
    }

    if ($action === 'webhook_save') {
        fc_exec(
            'UPDATE fc_webhooks
                SET label = :label, show_finance = :fin, board_enabled = :board,
                    enabled = :on, board_hash = NULL, fail_count = 0, last_error = NULL
              WHERE id = :id',
            [
                'label' => mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 64) ?: null,
                'fin' => isset($_POST['show_finance']) ? 1 : 0,
                'board' => 1,
                // Saving is also how a webhook disabled by repeated failures is
                // put back into service, so the counters are cleared above.
                'on' => isset($_POST['enabled']) ? 1 : 0,
                'id' => $hook['id'],
            ],
        );
        fc_webhook_board_refresh($carrierId);
        fc_webhook_flush_after_response();
        fc_flash('Webhook saved.');
        return;
    }

    if ($action === 'webhook_delete') {
        fc_exec('DELETE FROM fc_webhook_queue WHERE webhook_id = :id', ['id' => $hook['id']]);
        fc_exec('DELETE FROM fc_webhooks WHERE id = :id', ['id' => $hook['id']]);
        fc_flash('Webhook removed. Anything it already posted stays in the channel.');
        return;
    }

    if ($action === 'webhook_test') {
        $result = fc_webhook_test($hook);
        if ($result['ok']) {
            fc_flash('Test message sent.');
        } else {
            fc_flash('Discord refused it: ' . $result['error'], 'err');
        }
        return;
    }
}

/**
 * Post a one-off message now, for the "send a test" button.
 *
 * The only path that talks to Discord inside a request. It is a deliberate
 * button press by someone watching the page, so the wait is theirs to spend
 * and the answer is worth having immediately.
 */
function fc_webhook_test(array $hook): array
{
    $carrier = fc_carrier((int) $hook['carrier_id']);
    if ($carrier === null) {
        return ['ok' => false, 'error' => 'That carrier no longer exists.'];
    }

    $result = fc_discord_send((string) $hook['url'], [
        'embeds' => [[
            'title' => fc_webhook_carrier_title($carrier),
            'url' => fc_carrier_link($carrier),
            'description' => "**Webhook connected.**\nNotices for this carrier will arrive here.",
            'color' => 0x38bdf8,
            'timestamp' => gmdate('c'),
            'footer' => ['text' => 'Carrier Ops · test message'],
        ]],
        'allowed_mentions' => ['parse' => []],
    ]);

    if ($result['ok']) {
        fc_exec(
            'UPDATE fc_webhooks SET last_sent_at = UTC_TIMESTAMP(), fail_count = 0, last_error = NULL WHERE id = :id',
            ['id' => $hook['id']],
        );
        return ['ok' => true, 'error' => ''];
    }

    fc_exec('UPDATE fc_webhooks SET last_error = :e WHERE id = :id',
        ['e' => mb_substr($result['error'], 0, 255), 'id' => $hook['id']]);
    return ['ok' => false, 'error' => $result['error']];
}
