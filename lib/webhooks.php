<?php

declare(strict_types=1);

/**
 * A carrier's boards in Discord.
 *
 * One message per topic -- what the carrier is, where it is jumping, what it
 * is trading -- and each is edited for ever after it is first posted. Never a
 * message per event: a channel following an active carrier would fill with
 * one-line posts and bury the thing worth reading.
 *
 * The topic is the unit because that is how the information actually behaves.
 * A buy order appears, sits there, and is eventually filled; all three belong
 * to the same paragraph, so all three happen inside the one message the order
 * first appeared in. A jump is plotted, maybe cancelled, eventually completed;
 * same message throughout.
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
        foreach (fc_board_topics() as $topic) {
            $embed = fc_board_embed($topic, $carrier, $hook);
            $row = fc_board_message($hook['id'], $topic);

            if ($embed === null) {
                continue;   // this topic is switched off for this carrier
            }

            // A topic that has never had anything to say does not get a
            // message just to say so. Once one exists it is kept up to date
            // for ever, including when it becomes empty again -- an order
            // that fills should visibly disappear from the message it was
            // announced in, not take the message with it.
            if ($row === null && fc_board_is_empty($topic, $carrier)) {
                continue;
            }

            $payload = ['embeds' => [$embed]];

            // Hash what the message *says*, not when it was built. The embed
            // carries an "updated" timestamp of now, so hashing the payload
            // whole would differ every time and this check would never once
            // suppress a request -- an edit costs Discord a call whether or
            // not anything actually changed.
            $stable = $embed;
            unset($stable['timestamp'], $stable['footer']);
            $hash = sha1(json_encode($stable));
            if ($row !== null && $hash === $row['content_hash']) {
                continue;
            }

            fc_exec(
                'INSERT INTO fc_webhook_messages (webhook_id, topic, content_hash, created_at)
                 VALUES (:w, :t, :h, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash)',
                ['w' => $hook['id'], 't' => $topic, 'h' => $hash],
            );

            fc_webhook_enqueue((int) $hook['id'], $topic, $payload, sha1($hook['id'] . '|' . $topic . '|' . $hash));
        }
    }
}

/** The stored message for one webhook and topic, if it has ever been posted. */
function fc_board_message(int|string $webhookId, string $topic): ?array
{
    return fc_one(
        'SELECT * FROM fc_webhook_messages WHERE webhook_id = :w AND topic = :t',
        ['w' => $webhookId, 't' => $topic],
    );
}

/**
 * Whether a topic has nothing worth a message of its own yet.
 *
 * Only consulted before the first post. Status always qualifies -- a carrier
 * always has a position and a name -- but there is no sense in opening a
 * market message for a carrier that has never traded.
 */
function fc_board_is_empty(string $topic, array $carrier): bool
{
    $id = (int) $carrier['id'];
    return match ($topic) {
        'jumps' => (int) fc_one(
            "SELECT (SELECT COUNT(*) FROM fc_jumps WHERE carrier_id = :a AND status = 'scheduled'
                       AND departure_time > UTC_TIMESTAMP())
                  + (SELECT COUNT(*) FROM fc_itinerary WHERE carrier_id = :b AND departure_time IS NOT NULL) AS n",
            ['a' => $id, 'b' => $id],
        )['n'] === 0,
        'market' => (int) fc_one('SELECT COUNT(*) AS n FROM fc_orders WHERE carrier_id = :i', ['i' => $id])['n'] === 0,
        default => false,
    };
}

/**
 * The topics a webhook keeps a message for.
 *
 * Ordered as they are first posted, so a fresh channel reads top to bottom:
 * what the carrier is, then where it is going, then what it will trade.
 *
 * @return string[]
 */
function fc_board_topics(): array
{
    return ['status', 'jumps', 'market'];
}

/**
 * Short names for the services a visitor cares about.
 *
 * Frontier's own labels are written for the owner's management screen -- a
 * dock full of "Universal Cartographics" and "Redemption Office" is a wall of
 * text in an embed. These are what the thing is called when you are deciding
 * whether it is worth flying there. Bridge Crew is absent on purpose: every
 * carrier has one and nobody visits for it.
 */
function fc_board_service_label(string $role): ?string
{
    return [
        'Refuel' => 'Refuel',
        'Repair' => 'Repair',
        'Rearm' => 'Rearm',
        'Shipyard' => 'Shipyard',
        'Outfitting' => 'Outfitting',
        'Commodities' => 'Commodities',
        'BlackMarket' => 'Black market',
        'Bartender' => 'Bar',
        'Exploration' => 'Cartographics',
        'VistaGenomics' => 'Vista Genomics',
        'VoucherRedemption' => 'Redemption',
        'CarrierFuel' => 'Tritium depot',
        'PioneerSupplies' => 'Pioneer supplies',
    ][$role] ?? null;
}

/**
 * The services line, with stock counts attached to the two that have any.
 *
 * "Shipyard" and "Outfitting" mean little on their own -- the question is
 * always whether anything is in them -- so the number rides along with the
 * name rather than taking a field of its own.
 */
function fc_board_services(int $carrierId): string
{
    $ships = (int) (fc_one('SELECT COUNT(*) n FROM fc_shipyard WHERE carrier_id = :i', ['i' => $carrierId])['n'] ?? 0);
    $modules = (int) (fc_one('SELECT COUNT(*) n FROM fc_outfitting WHERE carrier_id = :i', ['i' => $carrierId])['n'] ?? 0);

    $out = [];
    foreach (fc_all('SELECT * FROM fc_crew WHERE carrier_id = :i', ['i' => $carrierId]) as $row) {
        if ((int) $row['activated'] !== 1 || (int) $row['enabled'] !== 1) {
            continue;   // not installed, or suspended: not a service anyone can use
        }
        $label = fc_board_service_label((string) $row['crew_role']);
        if ($label === null) {
            continue;
        }
        if ($row['crew_role'] === 'Shipyard' && $ships > 0) {
            $label .= ' (' . fc_num($ships) . ')';
        }
        if ($row['crew_role'] === 'Outfitting' && $modules > 0) {
            $label .= ' (' . fc_num($modules) . ')';
        }
        $out[] = $label;
    }

    sort($out);
    return implode(' · ', $out);
}

/**
 * What the carrier charges, as a visitor would ask it.
 *
 * Only the rates that cost anything are named. A carrier that charges nothing
 * says so outright, because "no taxes" is the thing people are hoping to read
 * and a row of five zeroes buries it.
 */
function fc_board_taxes(array $carrier): string
{
    $rates = [
        'Refuel' => $carrier['tax_refuel'],
        'Repair' => $carrier['tax_repair'],
        'Rearm' => $carrier['tax_rearm'],
        'Shipyard' => $carrier['tax_shipyard'],
        'Outfitting' => $carrier['tax_outfitting'],
    ];

    // Older carriers had one rate for everything, and old journals still carry
    // it; it stands in when the per-service ones were never reported.
    if (count(array_filter($rates, static fn($v) => $v !== null)) === 0) {
        $flat = $carrier['tax_rate'];
        if ($flat === null) {
            return '';
        }
        return (int) $flat === 0 ? 'None' : (int) $flat . '% on everything';
    }

    $charged = [];
    foreach ($rates as $label => $rate) {
        if ($rate !== null && (int) $rate > 0) {
            $charged[] = $label . ' ' . (int) $rate . '%';
        }
    }

    return $charged === [] ? 'None' : implode(' · ', $charged);
}

/**
 * What the carrier is buying and selling, best first.
 *
 * Standing orders rather than the commodity list: an order is a live offer
 * with a price and a quantity behind it, which is the thing worth flying to.
 */
function fc_board_trading(int $carrierId): string
{
    $rows = fc_all(
        'SELECT * FROM fc_orders WHERE carrier_id = :i ORDER BY kind DESC, amount DESC',
        ['i' => $carrierId],
    );
    if ($rows === []) {
        return '';
    }

    $lines = [];
    foreach (array_slice($rows, 0, 6) as $row) {
        $name = fc_discord_escape($row['loc_name'] ?: ucfirst((string) $row['commodity']));
        $lines[] = ((string) $row['kind'] === 'buy' ? '🟢 Buying ' : '🔵 Selling ')
            . '**' . $name . '** — ' . fc_num((int) $row['amount']) . ' t at '
            . fc_cr((int) $row['price']) . ' cr'
            . ((int) $row['black_market'] === 1 ? ' *(black market)*' : '');
    }
    if (count($rows) > 6) {
        $lines[] = '…and ' . (count($rows) - 6) . ' more';
    }

    return implode("\n", $lines);
}

/**
 * The parts every message shares.
 *
 * Modelled on how FCMS presents a carrier: an attributed embed with a titled
 * subject, a sentence naming the carrier, and a picture of it. Only the
 * presentation is borrowed -- FCMS posts a fresh message per event, which is
 * the one thing these boards deliberately do not do.
 */
function fc_board_chrome(array $carrier, string $title, string $sentence, int $colour, string $tab = ''): array
{
    return [
        'title' => $title,
        'url' => fc_carrier_link($carrier) . $tab,
        'description' => $sentence,
        'color' => $colour,
        'author' => [
            'name' => fc_webhook_carrier_title($carrier),
            'url' => fc_carrier_link($carrier),
        ],
        // Served by nginx as a static file, so Discord can still fetch it while
        // the board itself is closed for maintenance.
        'thumbnail' => ['url' => fc_base_url() . '/fc/assets/carrier-512.jpg'],
        'footer' => ['text' => 'Carrier Ops'],
        'timestamp' => gmdate('c'),
    ];
}

/** "V4H-84Q THE TOXINS", as FCMS words it in a webhook sentence. */
function fc_board_subject(array $carrier): string
{
    $callsign = $carrier['callsign'] ?? null;
    $name = fc_discord_escape($carrier['name'] ?? null);
    return trim(($callsign === null ? '' : '**' . $callsign . '** ') . $name) ?: 'This carrier';
}

/**
 * The status message: what the carrier is and what it offers.
 */
function fc_board_status_embed(array $carrier, bool $withFinance): array
{
    $system = fc_discord_escape($carrier['system'] ?? null);
    $sentence = fc_board_subject($carrier)
        . ($system === '' ? ' is somewhere unrecorded.' : ' is at **' . $system . '**.');

    $fields = [];

    $where = $system === '' ? 'Unknown' : $system;
    if (($carrier['body'] ?? null) !== null && $carrier['body'] !== '') {
        $where .= "\n" . fc_discord_escape((string) $carrier['body']);
    }
    // How long it has been parked. The stop has to still be open *and* name the
    // system the carrier is actually in: a stale open stop from somewhere else
    // would otherwise report an arrival that never happened here.
    if ($system !== '') {
        $stop = fc_one(
            'SELECT arrival_time FROM fc_itinerary
              WHERE carrier_id = :cid AND departure_time IS NULL AND system = :sys
              ORDER BY arrival_time DESC LIMIT 1',
            ['cid' => $carrier['id'], 'sys' => $carrier['system']],
        );
        if ($stop !== null) {
            $where .= "\nSince <t:" . strtotime((string) $stop['arrival_time'] . ' UTC') . ':R>';
        }
    }
    $fields[] = ['name' => 'Current Location', 'value' => $where, 'inline' => true];

    $fields[] = [
        'name' => 'Docking Access',
        'value' => fc_docking_label($carrier['docking_access'])
            . ($carrier['allow_notorious'] === null
                ? ''
                : "\n" . ((int) $carrier['allow_notorious'] === 1 ? 'Notorious welcome' : 'No notorious')),
        'inline' => true,
    ];

    $fuel = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
    $fields[] = [
        'name' => 'Tritium',
        'value' => ($fuel === null ? '—' : fc_num($fuel) . ' t' . ($fuel <= FC_WEBHOOK_LOW_FUEL ? ' ⚠️' : ''))
            . "\n" . ($carrier['space_free'] === null ? '' : fc_num((int) $carrier['space_free']) . ' t free'),
        'inline' => true,
    ];

    $services = fc_board_services((int) $carrier['id']);
    if ($services !== '') {
        $fields[] = ['name' => 'Services', 'value' => mb_substr($services, 0, 1024), 'inline' => false];
    }

    $taxes = fc_board_taxes($carrier);
    if ($taxes !== '') {
        $fields[] = ['name' => 'Taxes', 'value' => $taxes, 'inline' => false];
    }

    if ($withFinance && $carrier['balance'] !== null) {
        $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
        $upkeep = fc_upkeep($crew, $carrier);
        $solvency = fc_solvency($upkeep, (int) $carrier['balance']);
        $span = fc_weeks_span($solvency['weeks']);

        $fields[] = ['name' => 'Balance', 'value' => fc_cr((int) $carrier['balance']) . ' cr', 'inline' => true];
        $fields[] = ['name' => 'Upkeep', 'value' => fc_cr($upkeep['total']) . ' cr/wk', 'inline' => true];
        $fields[] = [
            'name' => 'Covered For',
            'value' => $span === null ? '—' : $span . (($solvency['weeks'] ?? 99) < 2 ? ' ⚠️' : ''),
            'inline' => true,
        ];
    }

    $motd = trim((string) ($carrier['motd'] ?? ''));
    if ($motd !== '') {
        $fields[] = ['name' => 'Message', 'value' => mb_substr(fc_discord_escape($motd), 0, 1024), 'inline' => false];
    }

    $embed = fc_board_chrome(
        $carrier,
        'Carrier Status',
        $sentence,
        ($fuel !== null && $fuel <= FC_WEBHOOK_LOW_FUEL) ? 0xf59e0b : 0x38bdf8,
    );
    $embed['fields'] = $fields;
    return $embed;
}

/**
 * The jumps message: where it is going, and where it has been.
 *
 * One message for the whole subject, titled after whatever it is currently
 * saying. A jump plotted here is the same jump cancelled or completed here
 * later -- the message is rewritten, not replaced.
 */
function fc_board_jumps_embed(array $carrier): array
{
    $here = fc_discord_escape($carrier['system'] ?? null) ?: 'an unrecorded system';

    $next = fc_one(
        "SELECT * FROM fc_jumps
          WHERE carrier_id = :cid AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['cid' => $carrier['id']],
    );

    $fields = [];
    if ($next !== null) {
        // DepartureTime is when the carrier *arrives*, not when it leaves.
        $at = strtotime((string) $next['departure_time'] . ' UTC');
        $title = 'Scheduled Jump';
        $sentence = fc_board_subject($carrier) . ' has scheduled a jump.';
        $colour = 0x3b82f6;

        $fields[] = ['name' => 'Departing From', 'value' => $here, 'inline' => true];
        $fields[] = [
            'name' => 'Headed To',
            'value' => fc_discord_escape((string) ($next['system'] ?? '?'))
                . (($next['body'] ?? null) === null ? '' : "\n" . fc_discord_escape((string) $next['body'])),
            'inline' => true,
        ];
        $fields[] = [
            'name' => 'Arrival Time',
            'value' => '<t:' . $at . ':t>' . "\n" . '<t:' . $at . ':R>',
            'inline' => true,
        ];
    } else {
        $title = 'Standing By';
        $sentence = fc_board_subject($carrier) . ' has no jump plotted.';
        $colour = 0x6b7280;
        $fields[] = ['name' => 'Staying Right Here In', 'value' => $here, 'inline' => true];
    }

    // Where it has been. Only closed stops: the open one is the current
    // position, which the status message already leads with.
    $stops = fc_all(
        'SELECT system, arrival_time FROM fc_itinerary
          WHERE carrier_id = :cid AND departure_time IS NOT NULL
          ORDER BY arrival_time DESC LIMIT 5',
        ['cid' => $carrier['id']],
    );
    if ($stops !== []) {
        $rows = [];
        foreach ($stops as $stop) {
            $rows[] = '**' . fc_discord_escape((string) $stop['system']) . '** — <t:'
                . strtotime((string) $stop['arrival_time'] . ' UTC') . ':R>';
        }
        $fields[] = ['name' => 'Previously', 'value' => implode("\n", $rows), 'inline' => false];
    }

    $embed = fc_board_chrome($carrier, $title, $sentence, $colour, '&tab=itinerary');
    $embed['fields'] = $fields;
    return $embed;
}

/**
 * The market message: what it will buy and sell.
 *
 * An order that is later filled simply stops being listed here, in the message
 * it was first announced in -- which is the whole point of keeping one.
 *
 * Laid out as FCMS lays out a market notification: each order is three inline
 * fields, so Discord puts one order per row.
 */
function fc_board_market_embed(array $carrier): ?array
{
    // The carrier's own market switch decides this, the same as it does on the
    // website -- one setting, not two that can disagree.
    if ((int) ($carrier['show_market'] ?? 1) !== 1) {
        return null;
    }

    $rows = fc_all(
        'SELECT * FROM fc_orders WHERE carrier_id = :i ORDER BY kind DESC, amount DESC',
        ['i' => (int) $carrier['id']],
    );

    $fields = [];
    // Six orders is eighteen fields; Discord allows twenty-five in an embed.
    foreach (array_slice($rows, 0, 6) as $row) {
        $fields[] = [
            'name' => (string) $row['kind'] === 'buy' ? '🟢 Buying' : '🔵 Selling',
            'value' => fc_discord_escape($row['loc_name'] ?: ucfirst((string) $row['commodity']))
                . ((int) $row['black_market'] === 1 ? "\n*black market*" : ''),
            'inline' => true,
        ];
        $fields[] = ['name' => 'For', 'value' => fc_cr((int) $row['price']) . ' cr', 'inline' => true];
        $fields[] = ['name' => 'Quantity', 'value' => fc_num((int) $row['amount']) . ' t', 'inline' => true];
    }

    if (count($rows) > 6) {
        $fields[] = ['name' => 'And', 'value' => (count($rows) - 6) . ' more orders', 'inline' => false];
    }

    $fields[] = [
        'name' => 'Current Location',
        'value' => fc_discord_escape($carrier['system'] ?? null) ?: 'Unknown',
        'inline' => true,
    ];
    $fields[] = [
        'name' => 'Docking Access',
        'value' => fc_docking_label($carrier['docking_access']),
        'inline' => true,
    ];

    $embed = fc_board_chrome(
        $carrier,
        $rows === [] ? 'Market Closed' : 'Market Update',
        $rows === []
            ? fc_board_subject($carrier) . ' has no standing orders.'
            : fc_board_subject($carrier) . ' has issued a market notification.',
        $rows === [] ? 0x6b7280 : 0x22c55e,
        '&tab=market',
    );
    $embed['fields'] = $fields;
    return $embed;
}

/** Build one topic's embed, or null when that topic has nothing to say. */
function fc_board_embed(string $topic, array $carrier, array $hook): ?array
{
    return match ($topic) {
        'status' => fc_board_status_embed($carrier, (int) $hook['show_finance'] === 1),
        'jumps' => fc_board_jumps_embed($carrier),
        'market' => fc_board_market_embed($carrier),
        default => null,
    };
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
        'SELECT q.*, w.url, w.enabled
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

        // Everything a webhook sends is a topic message; there is no other kind.
        $result = fc_discord_board_send(
            (int) $item['webhook_id'],
            (string) $item['kind'],
            (string) $item['url'],
            $payload,
        );

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
function fc_discord_board_send(int $webhookId, string $topic, string $url, array $payload): array
{
    $row = fc_board_message($webhookId, $topic);
    $messageId = $row['message_id'] ?? null;

    if ($messageId !== null) {
        $result = fc_discord_send($url . '/messages/' . rawurlencode($messageId), $payload, 'PATCH');
        if ($result['ok']) {
            fc_exec(
                'UPDATE fc_webhook_messages SET updated_at = UTC_TIMESTAMP() WHERE webhook_id = :w AND topic = :t',
                ['w' => $webhookId, 't' => $topic],
            );
            return $result;
        }
        if ($result['status'] !== 404) {
            return $result;
        }
        // Deleted at the far end. Forget it and fall through to a new post.
        fc_exec(
            'UPDATE fc_webhook_messages SET message_id = NULL WHERE webhook_id = :w AND topic = :t',
            ['w' => $webhookId, 't' => $topic],
        );
    }

    $result = fc_discord_send($url . '?wait=true', $payload);
    if ($result['ok'] && ($result['message_id'] ?? null) !== null) {
        fc_exec(
            'INSERT INTO fc_webhook_messages (webhook_id, topic, message_id, updated_at, created_at)
             VALUES (:w, :t, :m, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE message_id = VALUES(message_id), updated_at = UTC_TIMESTAMP()',
            ['w' => $webhookId, 't' => $topic, 'm' => $result['message_id']],
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
                    enabled = :on, fail_count = 0, last_error = NULL
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
        // Clearing the hashes makes the next refresh rewrite every message,
        // so a settings change is visible immediately rather than waiting for
        // the carrier to do something.
        fc_exec('UPDATE fc_webhook_messages SET content_hash = NULL WHERE webhook_id = :w', ['w' => $hook['id']]);
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
