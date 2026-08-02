<?php

declare(strict_types=1);

/**
 * JSON API.
 *
 *   POST ?action=ingest    raw journal text (or NDJSON) in the body
 *   GET  ?action=carrier   one carrier, id= accepts a CarrierID or a callsign
 *   GET  ?action=carriers  public carriers, optional q= search
 *   GET  ?action=me        the calling account
 *
 * Authenticate with an `X-API-Key` header, or just use the site session if you
 * are calling it from a signed-in browser.
 */

require __DIR__ . '/_lib.php';
require __DIR__ . '/_ingest.php';
require __DIR__ . '/_render.php';

header('Cache-Control: no-store');

function fc_api_user(): ?array
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($key === '' && isset($_GET['key'])) {
        $key = (string) $_GET['key'];
    }
    if ($key !== '') {
        return fc_user_by_api_key(trim($key));
    }
    return fc_user();
}

function fc_api_require_user(): array
{
    $user = fc_api_user();
    if ($user === null) {
        fc_json(401, ['error' => 'Provide a valid X-API-Key header, or sign in.']);
    }
    return $user;
}

/** The public shape of a carrier. Finance is stripped unless you own it. */
function fc_api_carrier(array $carrier, bool $owns): array
{
    $out = [
        'id' => (string) $carrier['id'],
        'callsign' => $carrier['callsign'],
        'name' => $carrier['name'],
        'system' => $carrier['system'],
        'body' => $carrier['body'],
        'coords' => $carrier['x'] === null ? null : [
            'x' => (float) $carrier['x'], 'y' => (float) $carrier['y'], 'z' => (float) $carrier['z'],
        ],
        'dockingAccess' => $carrier['docking_access'],
        'allowNotorious' => (bool) $carrier['allow_notorious'],
        'fuelLevel' => $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'],
        'jumpRange' => [
            'current' => $carrier['jump_range_curr'] === null ? null : (float) $carrier['jump_range_curr'],
            'max' => $carrier['jump_range_max'] === null ? null : (float) $carrier['jump_range_max'],
        ],
        'pendingDecommission' => (bool) $carrier['pending_decommission'],
        'space' => [
            'capacity' => $carrier['capacity'] === null ? null : (int) $carrier['capacity'],
            'cargo' => $carrier['space_cargo'] === null ? null : (int) $carrier['space_cargo'],
            'reserved' => $carrier['space_reserved'] === null ? null : (int) $carrier['space_reserved'],
            'crew' => $carrier['space_crew'] === null ? null : (int) $carrier['space_crew'],
            'shipPacks' => $carrier['space_shippacks'] === null ? null : (int) $carrier['space_shippacks'],
            'modulePacks' => $carrier['space_modulepacks'] === null ? null : (int) $carrier['space_modulepacks'],
            'free' => $carrier['space_free'] === null ? null : (int) $carrier['space_free'],
        ],
        'updatedAt' => $carrier['updated_at'],
        'locationAt' => $carrier['location_at'],
        'statsAt' => $carrier['stats_at'],
        'owned' => $owns,
    ];

    if ($owns) {
        $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
        $upkeep = fc_upkeep($crew, $carrier);
        $lastTick = fc_last_upkeep_tick();
        $jumps = (int) (fc_one(
            'SELECT COUNT(*) AS n FROM fc_itinerary WHERE carrier_id = :id AND arrival_time >= :since',
            ['id' => $carrier['id'], 'since' => gmdate('Y-m-d H:i:s', $lastTick)],
        )['n'] ?? 0);
        $solvency = fc_solvency($upkeep, $carrier['balance'] === null ? null : (int) $carrier['balance'], $jumps);

        $out['finance'] = [
            'balance' => $carrier['balance'] === null ? null : (int) $carrier['balance'],
            'reserveBalance' => $carrier['reserve_balance'] === null ? null : (int) $carrier['reserve_balance'],
            'availableBalance' => $carrier['available_balance'] === null ? null : (int) $carrier['available_balance'],
            'reservePercent' => $carrier['reserve_percent'] === null ? null : (int) $carrier['reserve_percent'],
            'taxRates' => array_filter([
                'flat' => $carrier['tax_rate'],
                'refuel' => $carrier['tax_refuel'],
                'repair' => $carrier['tax_repair'],
                'rearm' => $carrier['tax_rearm'],
                'shipyard' => $carrier['tax_shipyard'],
                'outfitting' => $carrier['tax_outfitting'],
            ], static fn($v) => $v !== null),
        ];
        $out['upkeep'] = [
            'core' => $upkeep['core'],
            'services' => $upkeep['services'],
            'weekly' => $upkeep['total'],
            'jumpFeesThisWeek' => $solvency['jump_fees'],
            'weeksSolvent' => $solvency['weeks'],
            'solventFor' => fc_weeks_span($solvency['weeks']),
            'nextChargeAt' => gmdate('c', fc_next_upkeep_tick()),
            // False once a Companion API payload has supplied the game's own
            // coreCost and servicesCost; the per-service lines stay derived.
            'estimated' => !$upkeep['exact'],
            'lines' => $upkeep['lines'],
        ];
        $out['cargo'] = array_map(static fn(array $c) => [
            'commodity' => $c['commodity'],
            'name' => $c['loc_name'],
            'qty' => (int) $c['qty'],
            'value' => (int) $c['value'],
            'stolen' => (bool) $c['stolen'],
        ], fc_all('SELECT * FROM fc_cargo WHERE carrier_id = :id ORDER BY qty DESC', ['id' => $carrier['id']]));

        $out['crew'] = array_map(static fn(array $m) => [
            'role' => $m['crew_role'],
            'service' => fc_service_label((string) $m['crew_role']),
            'name' => $m['crew_name'],
            'installed' => (bool) $m['activated'],
            'active' => (bool) $m['enabled'],
        ], $crew);
    }

    return $out;
}

$action = (string) ($_GET['action'] ?? '');

switch ($action) {

case 'me':
    $user = fc_api_require_user();
    fc_json(200, [
        'username' => $user['username'],
        'cmdr' => $user['cmdr_name'],
        'isAdmin' => (int) $user['is_admin'] === 1,
        'carriers' => array_map(
            static fn(array $c) => ['id' => (string) $c['id'], 'callsign' => $c['callsign'], 'name' => $c['name']],
            fc_all('SELECT id, callsign, name FROM fc_carriers WHERE owner_user_id = :uid', ['uid' => $user['id']]),
        ),
    ]);

case 'ingest':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fc_json(405, ['error' => 'POST the journal text to this endpoint.']);
    }
    $user = fc_api_require_user();

    // Accept both a raw body and a multipart upload, so the same endpoint
    // works from curl and from a form.
    $chunks = [];
    if (!empty($_FILES['journals']['name']) && is_array($_FILES['journals']['name'])) {
        foreach ($_FILES['journals']['name'] as $i => $name) {
            if ((int) $_FILES['journals']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $text = file_get_contents($_FILES['journals']['tmp_name'][$i]);
            if ($text !== false) {
                $chunks[] = [basename((string) $name), $text];
            }
        }
    } else {
        $body = file_get_contents('php://input');
        if ($body === false || trim($body) === '') {
            fc_json(400, ['error' => 'Empty request body.']);
        }
        if (strlen($body) > FC_MAX_UPLOAD_BYTES) {
            fc_json(413, ['error' => 'That is larger than the upload limit.']);
        }
        $chunks[] = [basename((string) ($_GET['filename'] ?? 'api')), $body];
    }

    if ($chunks === []) {
        fc_json(400, ['error' => 'Nothing to read.']);
    }

    $totals = ['seen' => 0, 'applied' => 0, 'carriers' => [], 'notes' => []];
    foreach ($chunks as [$name, $text]) {
        $report = fc_ingest_text($text, $user, $name, 'api');
        $totals['seen'] += $report['seen'];
        $totals['applied'] += $report['applied'];
        $totals['carriers'] += $report['carriers'];
        foreach ($report['notes'] as $note) {
            if (!in_array($note, $totals['notes'], true)) {
                $totals['notes'][] = $note;
            }
        }
    }

    fc_json(200, [
        'eventsSeen' => $totals['seen'],
        'eventsApplied' => $totals['applied'],
        'carriers' => array_map(
            static fn($id, $label) => ['id' => (string) $id, 'callsign' => $label],
            array_keys($totals['carriers']),
            array_values($totals['carriers']),
        ),
        'notes' => $totals['notes'],
    ]);

case 'carrier':
    $user = fc_api_user();
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        fc_json(400, ['error' => 'Pass id= with a CarrierID or a callsign.']);
    }
    $carrier = ctype_digit($id) ? fc_carrier((int) $id) : fc_carrier_by_callsign($id);
    if ($carrier === null) {
        fc_json(404, ['error' => 'No carrier with that id or callsign.']);
    }

    $owns = fc_owns($user, $carrier);
    if (!$owns && (int) $carrier['is_public'] !== 1) {
        fc_json(404, ['error' => 'No carrier with that id or callsign.']);
    }

    $out = fc_api_carrier($carrier, $owns);

    if (fc_can_view($user, $carrier, 'market')) {
        $out['market'] = array_map(static fn(array $m) => [
            'commodity' => $m['commodity'],
            'name' => $m['loc_name'],
            'category' => $m['category'],
            'stock' => (int) $m['stock'],
            'demand' => (int) $m['demand'],
            'buyPrice' => (int) $m['buy_price'],
            'sellPrice' => (int) $m['sell_price'],
        ], fc_all('SELECT * FROM fc_market WHERE carrier_id = :id ORDER BY commodity', ['id' => $carrier['id']]));

        $out['orders'] = array_map(static fn(array $o) => [
            'commodity' => $o['commodity'],
            'name' => $o['loc_name'],
            'kind' => $o['kind'],
            'amount' => (int) $o['amount'],
            'price' => (int) $o['price'],
            'blackMarket' => (bool) $o['black_market'],
        ], fc_all('SELECT * FROM fc_orders WHERE carrier_id = :id', ['id' => $carrier['id']]));
    }

    if (fc_can_view($user, $carrier, 'itinerary')) {
        $out['itinerary'] = array_map(static fn(array $i) => [
            'system' => $i['system'],
            'body' => $i['body'],
            'arrivedAt' => $i['arrival_time'],
            'departedAt' => $i['departure_time'],
        ], fc_all(
            'SELECT * FROM fc_itinerary WHERE carrier_id = :id ORDER BY arrival_time DESC LIMIT 100',
            ['id' => $carrier['id']],
        ));

        $out['scheduledJump'] = fc_one(
            "SELECT system, body, departure_time FROM fc_jumps
              WHERE carrier_id = :id AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
              ORDER BY departure_time ASC LIMIT 1",
            ['id' => $carrier['id']],
        );
    }

    fc_json(200, $out);

case 'carriers':
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $rows = fc_all(
            'SELECT * FROM fc_carriers
              WHERE is_public = 1 AND (name LIKE :a OR callsign LIKE :b OR system LIKE :c)
              ORDER BY updated_at DESC LIMIT 100',
            ['a' => $like, 'b' => $like, 'c' => $like],
        );
    } else {
        $rows = fc_all('SELECT * FROM fc_carriers WHERE is_public = 1 ORDER BY updated_at DESC LIMIT 100');
    }

    fc_json(200, [
        'carriers' => array_map(static fn(array $c) => [
            'id' => (string) $c['id'],
            'callsign' => $c['callsign'],
            'name' => $c['name'],
            'system' => $c['system'],
            'dockingAccess' => $c['docking_access'],
            'fuelLevel' => $c['fuel_level'] === null ? null : (int) $c['fuel_level'],
            'updatedAt' => $c['updated_at'],
        ], $rows),
    ]);

default:
    fc_json(400, [
        'error' => 'Unknown action.',
        'actions' => ['ingest', 'carrier', 'carriers', 'me'],
    ]);
}
