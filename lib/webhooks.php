<?php

declare(strict_types=1);

/**
 * Announcing carrier activity to Discord.
 *
 * Two different things share this file, because they share a delivery path:
 *
 *   Notices      one post per thing that happened -- a jump scheduled, an
 *                arrival, docking access changed. These accumulate in the
 *                channel as a history.
 *
 *   Status board a single message that is *edited* rather than reposted, so a
 *                channel can hold one always-current summary of the carrier
 *                without a hundred stale copies of it above.
 *
 * The board is the reason anything here keeps message ids. A webhook POST
 * normally answers `204 No Content` and tells you nothing about what it just
 * created; posting to `...?wait=true` instead returns the message object, and
 * its `id` is what later `PATCH .../messages/{id}` calls need. Discord places
 * no time limit on editing a webhook's own message, but a webhook can only
 * touch messages it sent itself.
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

/**
 * How old an event can be and still be worth announcing.
 *
 * Uploading a year of journals is a routine thing to do here -- the first
 * backfill on this board replayed 1,146 events -- and every arrival in it is
 * new to the database. Without this, pointing a webhook at a channel and then
 * catching up on history would post forty-eight jump announcements for
 * journeys that finished months ago. A notice is only interesting while it is
 * still news.
 */
const FC_WEBHOOK_MAX_AGE_SECONDS = 6 * 3600;

/**
 * Notices a single upload may queue before it is treated as a backfill.
 *
 * The age guard above catches old journals. This catches the other case: a
 * genuinely busy session, or a clock that disagrees with ours. Past this many
 * the individual notices are dropped in favour of one summary, so the worst a
 * webhook can do to a channel is one message per upload.
 */
const FC_WEBHOOK_MAX_PER_INGEST = 8;

/** Consecutive failures before a webhook is switched off and left for its owner. */
const FC_WEBHOOK_MAX_FAILS = 10;

/** Sends attempted per flush. Discord allows about five a second per webhook. */
const FC_WEBHOOK_FLUSH_LIMIT = 12;

/** Tritium at or below this is worth saying out loud. */
const FC_WEBHOOK_LOW_FUEL = 150;

/**
 * What a webhook can be subscribed to.
 *
 * `default` is what a new webhook starts with: the things that change where
 * the carrier is or who can dock at it, which is what a squadron channel
 * actually wants. Trade orders and finance are opt-in because they are
 * chatty and, in the second case, nobody else's business.
 */
function fc_webhook_kinds(): array
{
    return [
        'jump.scheduled' => ['label' => 'Jump scheduled', 'default' => true,
            'hint' => 'A jump is plotted, with its destination and arrival time.'],
        'jump.completed' => ['label' => 'Arrival', 'default' => true,
            'hint' => 'The carrier reaches a system.'],
        'jump.cancelled' => ['label' => 'Jump cancelled', 'default' => true,
            'hint' => 'A plotted jump is called off.'],
        'docking' => ['label' => 'Docking access', 'default' => true,
            'hint' => 'Access changes between all, friends, squadron or none.'],
        'fuel' => ['label' => 'Fuel', 'default' => false,
            'hint' => 'Tritium deposited, and a warning below ' . FC_WEBHOOK_LOW_FUEL . ' t.'],
        'orders' => ['label' => 'Trade orders', 'default' => false,
            'hint' => 'Buy and sell orders being set or cancelled. Noisy.'],
        'finance' => ['label' => 'Finance', 'default' => false,
            'hint' => 'Upkeep warnings when the balance will not cover it. Never posts a balance unless you tick the box below.'],
        'decommission' => ['label' => 'Decommission', 'default' => true,
            'hint' => 'The carrier is scheduled for decommission, or that is called off.'],
    ];
}

/** @return string[] */
function fc_webhook_default_kinds(): array
{
    $out = [];
    foreach (fc_webhook_kinds() as $kind => $spec) {
        if ($spec['default']) {
            $out[] = $kind;
        }
    }
    return $out;
}

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

/** @return array<int,array> webhooks on this carrier subscribed to $kind */
function fc_webhooks_for(int $carrierId, string $kind): array
{
    $rows = fc_all(
        'SELECT * FROM fc_webhooks WHERE carrier_id = :cid AND enabled = 1',
        ['cid' => $carrierId],
    );
    return array_values(array_filter($rows, static function (array $row) use ($kind): bool {
        return in_array($kind, explode(',', (string) $row['events']), true);
    }));
}

/**
 * How many notices this request has queued.
 *
 * Static rather than a column: the cap is per upload, and an upload is exactly
 * one request.
 */
function fc_webhook_counter(bool $increment = false): int
{
    static $count = 0;
    if ($increment) {
        $count++;
    }
    return $count;
}

/**
 * Put one notice in the queue for every webhook that asked for this kind.
 *
 * `$dedupeKey` must identify the *thing that happened*, not the moment we
 * noticed it: the same jump seen again in a re-uploaded journal has to collide
 * with the row already sitting here, or the channel gets it twice.
 */
function fc_webhook_notify(int $carrierId, string $kind, string $dedupeKey, array $embed): void
{
    $hooks = fc_webhooks_for($carrierId, $kind);
    if ($hooks === []) {
        return;
    }

    if (fc_webhook_counter() >= FC_WEBHOOK_MAX_PER_INGEST) {
        fc_webhook_counter(true);   // keep counting, so the summary knows the total
        return;
    }
    fc_webhook_counter(true);

    foreach ($hooks as $hook) {
        fc_webhook_enqueue(
            (int) $hook['id'],
            $kind,
            ['embeds' => [$embed]],
            sha1($hook['id'] . '|' . $kind . '|' . $dedupeKey),
        );
    }
}

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

/**
 * If an upload tripped the burst cap, say so once instead of not at all.
 *
 * Called at the end of an ingest, when the final count is known.
 */
function fc_webhook_summarise_burst(int $carrierId): void
{
    $total = fc_webhook_counter();
    if ($total <= FC_WEBHOOK_MAX_PER_INGEST) {
        return;
    }

    $carrier = fc_carrier($carrierId);
    if ($carrier === null) {
        return;
    }
    $dropped = $total - FC_WEBHOOK_MAX_PER_INGEST;

    foreach (fc_all('SELECT * FROM fc_webhooks WHERE carrier_id = :cid AND enabled = 1', ['cid' => $carrierId]) as $hook) {
        fc_webhook_enqueue(
            (int) $hook['id'],
            'summary',
            ['embeds' => [[
                'title' => fc_webhook_carrier_title($carrier),
                'description' => 'A large upload was processed. ' . number_format($dropped)
                    . ' further ' . ($dropped === 1 ? 'notice was' : 'notices were')
                    . ' suppressed to keep this channel readable.',
                'color' => 0x6b7280,
                'url' => fc_carrier_link($carrier),
                'timestamp' => gmdate('c'),
            ]]],
            // One per webhook per minute at most, whatever the upload contained.
            sha1($hook['id'] . '|summary|' . gmdate('Y-m-d H:i')),
        );
    }
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

/**
 * Build and queue whatever this event is worth saying.
 *
 * `$before` is the carrier row as it was when the event arrived and `$carrier`
 * as it is now, which is how a fuel level crossing a threshold can be told
 * apart from one that was already below it.
 */
function fc_webhook_on_event(array $carrier, ?array $before, array $event, string $name, ?string $ts): void
{
    // Old news. See FC_WEBHOOK_MAX_AGE_SECONDS.
    if ($ts === null || strtotime($ts . ' UTC') < time() - FC_WEBHOOK_MAX_AGE_SECONDS) {
        return;
    }

    $id = (int) $carrier['id'];
    $title = fc_webhook_carrier_title($carrier);
    $link = fc_carrier_link($carrier);
    $base = ['title' => $title, 'url' => $link, 'timestamp' => gmdate('c', (int) strtotime($ts . ' UTC'))];

    switch ($name) {
        case 'CarrierJumpRequest':
            $system = (string) ($event['SystemName'] ?? '?');
            $body = $event['Body'] ?? null;
            $arrival = strtotime((string) ($event['DepartureTime'] ?? '')) ?: null;
            // DepartureTime names the moment the carrier *arrives*, not the
            // moment it leaves -- confirmed against a jump whose CarrierLocation
            // landed on the same second. Wording it as departure here would be
            // repeating Frontier's mistake into somebody's channel.
            $when = $arrival === null ? '' : "\nArrives <t:{$arrival}:R>, at <t:{$arrival}:t>.";
            fc_webhook_notify($id, 'jump.scheduled', 'jumpreq|' . $system . '|' . ($event['DepartureTime'] ?? $ts), $base + [
                'description' => '**Jump plotted to ' . fc_discord_escape($system) . '**'
                    . ($body === null ? '' : "\nBody: " . fc_discord_escape((string) $body)) . $when,
                'color' => 0x3b82f6,
            ]);
            return;

        case 'CarrierJumpCancelled':
            fc_webhook_notify($id, 'jump.cancelled', 'jumpcancel|' . $ts, $base + [
                'description' => '**Jump cancelled.**',
                'color' => 0xf59e0b,
            ]);
            return;

        case 'CarrierJump':
            $system = (string) ($event['StarSystem'] ?? ($event['SystemName'] ?? '?'));
            $body = $event['Body'] ?? null;
            fc_webhook_notify($id, 'jump.completed', 'arrive|' . $system . '|' . $ts, $base + [
                'description' => '**Arrived in ' . fc_discord_escape($system) . '**'
                    . ($body === null ? '' : "\nBody: " . fc_discord_escape((string) $body)),
                'color' => 0x22c55e,
                'fields' => array_values(array_filter([
                    $carrier['fuel_level'] === null ? null
                        : ['name' => 'Tritium', 'value' => fc_num((int) $carrier['fuel_level']) . ' t', 'inline' => true],
                    ['name' => 'Docking', 'value' => fc_docking_label($carrier['docking_access']), 'inline' => true],
                ])),
            ]);
            return;

        case 'CarrierDockingPermission':
            $access = fc_docking_label($event['DockingAccess'] ?? null);
            $notorious = !empty($event['AllowNotorious']);
            fc_webhook_notify($id, 'docking', 'docking|' . ($event['DockingAccess'] ?? '') . '|' . (int) $notorious . '|' . $ts, $base + [
                'description' => '**Docking access is now ' . $access . '**'
                    . "\nNotorious commanders " . ($notorious ? 'are' : 'are not') . ' permitted.',
                'color' => 0x8b5cf6,
            ]);
            return;

        case 'CarrierDepositFuel':
            $total = isset($event['Total']) ? (int) $event['Total'] : null;
            $amount = isset($event['Amount']) ? (int) $event['Amount'] : null;
            fc_webhook_notify($id, 'fuel', 'fuel|' . $ts . '|' . (string) $amount, $base + [
                'description' => '**' . ($amount === null ? 'Tritium deposited' : fc_num($amount) . ' t of tritium deposited') . '**'
                    . ($total === null ? '' : "\nReserve now " . fc_num($total) . ' t.'),
                'color' => 0x14b8a6,
            ]);
            return;

        case 'CarrierTradeOrder':
            $commodity = $event['Commodity_Localised'] ?? fc_clean_symbol($event['Commodity'] ?? null);
            if ($commodity === '') {
                return;
            }
            $price = isset($event['Price']) ? (int) $event['Price'] : 0;
            $purchase = (int) ($event['PurchaseOrder'] ?? 0);
            $sale = (int) ($event['SaleOrder'] ?? 0);

            if (!empty($event['CancelTrade'])) {
                $line = '**Order cancelled: ' . fc_discord_escape((string) $commodity) . '**';
                $colour = 0x6b7280;
                $key = 'cancel';
            } elseif ($purchase > 0) {
                $line = '**Buying ' . fc_num($purchase) . ' t of ' . fc_discord_escape((string) $commodity) . '**'
                    . "\n" . fc_cr($price) . ' cr per tonne.';
                $colour = 0x3b82f6;
                $key = 'buy|' . $purchase;
            } elseif ($sale > 0) {
                $line = '**Selling ' . fc_num($sale) . ' t of ' . fc_discord_escape((string) $commodity) . '**'
                    . "\n" . fc_cr($price) . ' cr per tonne.';
                $colour = 0x22c55e;
                $key = 'sell|' . $sale;
            } else {
                return;
            }

            fc_webhook_notify($id, 'orders', 'order|' . $commodity . '|' . $key . '|' . $price, $base + [
                'description' => $line,
                'color' => $colour,
            ]);
            return;

        case 'CarrierDecommission':
            fc_webhook_notify($id, 'decommission', 'decom|' . $ts, $base + [
                'description' => '**Decommission scheduled.**'
                    . (isset($event['ScrapRefund']) ? "\nRefund: " . fc_cr((int) $event['ScrapRefund']) . ' cr.' : ''),
                'color' => 0xef4444,
            ]);
            return;

        case 'CarrierCancelDecommission':
            fc_webhook_notify($id, 'decommission', 'decomcancel|' . $ts, $base + [
                'description' => '**Decommission cancelled.** The carrier stays.',
                'color' => 0x22c55e,
            ]);
            return;

        case 'CarrierStats':
            // Only worth a word on the way *down* past the threshold: a carrier
            // that sits at 80 t would otherwise announce it on every upload.
            $now = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
            $was = ($before['fuel_level'] ?? null) === null ? null : (int) $before['fuel_level'];
            if ($now !== null && $now <= FC_WEBHOOK_LOW_FUEL && ($was === null || $was > FC_WEBHOOK_LOW_FUEL)) {
                fc_webhook_notify($id, 'fuel', 'lowfuel|' . intdiv($now, 10), $base + [
                    'description' => '**Tritium low: ' . fc_num($now) . ' t**'
                        . "\nRoughly " . max(0, intdiv($now, 5)) . ' jumps of reserve at 5 t each.',
                    'color' => 0xf59e0b,
                ]);
            }
            return;
    }
}

/**
 * Warn when the balance will not cover the next upkeep tick.
 *
 * Kept apart from the event switch because it is a *state* worth reporting
 * rather than something that happened, and it is only knowable once finance
 * and the crew roster have both been seen.
 */
function fc_webhook_check_finance(int $carrierId): void
{
    if (fc_webhooks_for($carrierId, 'finance') === []) {
        return;
    }
    $carrier = fc_carrier($carrierId);
    if ($carrier === null || $carrier['balance'] === null) {
        return;
    }

    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrierId]);
    $upkeep = fc_upkeep($crew, $carrier);
    $solvency = fc_solvency($upkeep, (int) $carrier['balance']);
    $weeks = $solvency['weeks'];

    if ($weeks === null || $weeks > 2) {
        return;
    }

    $tick = fc_next_upkeep_tick();
    fc_webhook_notify($carrierId, 'finance', 'solvency|' . $weeks . '|' . gmdate('Y-W'), [
        'title' => fc_webhook_carrier_title($carrier),
        'url' => fc_carrier_link($carrier),
        'timestamp' => gmdate('c'),
        'description' => $weeks < 1
            ? "**Upkeep is not covered.**\nThe next charge is <t:{$tick}:R> and the balance will not meet it."
            : '**Upkeep is covered for about ' . $weeks . ' more ' . ($weeks === 1 ? 'week' : 'weeks') . ".**\nNext charge <t:{$tick}:R>.",
        'color' => $weeks < 1 ? 0xef4444 : 0xf59e0b,
        'fields' => [
            ['name' => 'Weekly upkeep', 'value' => fc_cr($upkeep['total']) . ' cr', 'inline' => true],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// The status board
// ---------------------------------------------------------------------------

/**
 * Rebuild the always-current summary and queue an edit if it changed.
 *
 * `board_hash` is what makes this cheap to call after every upload: an upload
 * that moved nothing produces the same board, and an unchanged board is not
 * worth a request to Discord.
 */
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

function fc_webhook_board_embed(array $carrier, bool $withFinance): array
{
    $fields = [];

    $where = fc_discord_escape($carrier['system'] ?? null);
    if ($where === '') {
        $where = 'Unknown';
    } elseif (($carrier['body'] ?? null) !== null && $carrier['body'] !== '') {
        $where .= "\n" . fc_discord_escape((string) $carrier['body']);
    }
    $fields[] = ['name' => 'Location', 'value' => $where, 'inline' => true];
    $fields[] = ['name' => 'Docking', 'value' => fc_docking_label($carrier['docking_access']), 'inline' => true];

    if ($carrier['fuel_level'] !== null) {
        $fields[] = ['name' => 'Tritium', 'value' => fc_num((int) $carrier['fuel_level']) . ' t', 'inline' => true];
    }
    if ($carrier['jump_range_curr'] !== null) {
        $fields[] = ['name' => 'Jump range', 'value' => rtrim(rtrim(number_format((float) $carrier['jump_range_curr'], 2), '0'), '.') . ' ly', 'inline' => true];
    }
    if ($carrier['space_free'] !== null) {
        $fields[] = ['name' => 'Free space', 'value' => fc_num((int) $carrier['space_free']) . ' t', 'inline' => true];
    }

    // A jump still in the future is the single most useful thing a channel can
    // be told, so it goes in whatever else is known.
    $next = fc_one(
        "SELECT * FROM fc_jumps
          WHERE carrier_id = :cid AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['cid' => $carrier['id']],
    );
    if ($next !== null) {
        $at = strtotime((string) $next['departure_time'] . ' UTC');
        $fields[] = [
            'name' => 'Next jump',
            'value' => fc_discord_escape((string) ($next['system'] ?? '?')) . "\nArrives <t:{$at}:R>",
            'inline' => true,
        ];
    }

    if ($withFinance && $carrier['balance'] !== null) {
        $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
        $upkeep = fc_upkeep($crew, $carrier);
        $solvency = fc_solvency($upkeep, (int) $carrier['balance']);
        $span = fc_weeks_span($solvency['weeks']);
        $fields[] = ['name' => 'Balance', 'value' => fc_cr((int) $carrier['balance']) . ' cr', 'inline' => true];
        $fields[] = [
            'name' => 'Upkeep',
            'value' => fc_cr($upkeep['total']) . ' cr/wk' . ($span === null ? '' : "\n" . $span . ' covered'),
            'inline' => true,
        ];
    }

    $motd = trim((string) ($carrier['motd'] ?? ''));

    return [
        'title' => fc_webhook_carrier_title($carrier),
        'url' => fc_carrier_link($carrier),
        'description' => $motd === '' ? null : fc_discord_escape($motd),
        'color' => 0x38bdf8,
        'fields' => $fields,
        'footer' => ['text' => 'Carrier Ops · updated'],
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

    $chosenKinds = static function (): string {
        $valid = array_keys(fc_webhook_kinds());
        $picked = array_values(array_intersect($valid, (array) ($_POST['events'] ?? [])));
        return implode(',', $picked);
    };

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
            'INSERT INTO fc_webhooks (carrier_id, created_by, label, url, events, show_finance, board_enabled, created_at)
             VALUES (:cid, :uid, :label, :url, :events, :fin, :board, UTC_TIMESTAMP())',
            [
                'cid' => $carrierId,
                'uid' => (int) ($carrier['owner_user_id'] ?? 0) ?: null,
                'label' => mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 64) ?: null,
                'url' => $url,
                'events' => $chosenKinds() ?: implode(',', fc_webhook_default_kinds()),
                'fin' => isset($_POST['show_finance']) ? 1 : 0,
                'board' => isset($_POST['board_enabled']) ? 1 : 0,
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
                SET label = :label, events = :events, show_finance = :fin, board_enabled = :board,
                    enabled = :on, board_hash = NULL, fail_count = 0, last_error = NULL
              WHERE id = :id',
            [
                'label' => mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 64) ?: null,
                'events' => $chosenKinds(),
                'fin' => isset($_POST['show_finance']) ? 1 : 0,
                'board' => isset($_POST['board_enabled']) ? 1 : 0,
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
