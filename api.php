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

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/ingest.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/colony.php';

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

/**
 * The caller of a colony route, which may not be an account at all.
 *
 * Answers 401 rather than returning null: every colony route needs one, and
 * three copies of the same check would be three chances to forget it.
 */
function fc_api_colony_caller(): array
{
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
    $caller = fc_colony_caller($key, fc_api_user());
    if ($caller === null) {
        fc_json(401, ['error' => 'Provide an account key, or a build token, in X-API-Key.']);
    }
    return $caller;
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
            // Worth of the whole stack, as the Companion API reports it — not
            // a unit price.
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

    // Checked here rather than at the top of the request so that reading data
    // back, and the plugin's own connection test, keep working while an
    // address is still waiting to be confirmed.
    fc_require_link($user);
    fc_require_upload_quota($user);

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

    // The hold, item by item. `space.cargo` above is the tonnage and answers
    // "how full is it"; this answers "is the steel I need already aboard",
    // which nothing outside the board could ask before.
    //
    // Gated on the same 'cargo' topic the web page uses, which for a personal
    // carrier means the owner and nobody else -- what is in the hold is not
    // public the way an advertised market is.
    if (fc_can_view($user, $carrier, 'cargo')) {
        $out['cargo'] = array_map(static fn(array $c) => [
            'commodity' => $c['commodity'],
            'name' => $c['loc_name'],
            'stolen' => (bool) $c['stolen'],
            'quantity' => (int) $c['qty'],
            'value' => (int) $c['value'],
        ], fc_all(
            'SELECT * FROM fc_cargo WHERE carrier_id = :id ORDER BY qty DESC, commodity',
            ['id' => $carrier['id']],
        ));
        // How current the manifest above actually is -- which is a different
        // question from when Frontier was last asked.
        //
        // cargo_at is the Companion API's stamp. But a CargoTransfer is applied
        // as a delta the moment the plugin reports it, so between syncs the
        // manifest runs ahead of that timestamp, and cargo_journal_at records
        // how far. Reporting the older of the two invites precisely the mistake
        // this field exists to prevent: a client adds its own copy of a transfer
        // the manifest already contains, and 363 t reads as 726 t.
        //
        // Both are 'Y-m-d H:i:s' in UTC, so the later one sorts later.
        $stamps = array_filter([$carrier['cargo_at'], $carrier['cargo_journal_at']]);
        $out['cargoAt'] = $stamps === [] ? null : max($stamps);
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

/*
 * A colonisation build several people are hauling to.
 *
 * Both routes want a key. Reading is not public the way a carrier's advertised
 * market is: a build's shopping list, who is carrying what and where they are
 * up to is the group's business, and the group is whoever has an account here.
 */
case 'colony_report':
    $caller = fc_api_colony_caller();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fc_json(405, ['error' => 'POST a report to this one.']);
    }

    $body = file_get_contents('php://input');
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        fc_json(400, ['error' => 'Send a JSON object describing what you can see.']);
    }

    // A token may only speak about sites in its own system. Checked against the
    // stored site where there is one; for a site nobody has reported yet, the
    // system in the report itself is all there is to go on, so it has to match
    // -- otherwise a token could invent a build anywhere.
    if ($caller['system'] !== null) {
        $site = fc_colony_site((int) ($data['marketId'] ?? 0));
        $system = $site['system'] ?? ($data['system'] ?? null);
        if (!is_string($system) || strcasecmp($system, $caller['system']) !== 0) {
            fc_json(403, ['error' => 'That token is for ' . $caller['system'] . '.']);
        }
    }

    $result = fc_colony_apply_report($caller['hauler'], $data);
    if (!$result['ok']) {
        fc_json(400, ['error' => $result['note'] ?? 'The report could not be applied.']);
    }

    // The merged view comes straight back, so a planner that reports does not
    // then have to ask -- one call per refresh rather than two.
    $site = fc_colony_site((int) $result['market_id']);
    fc_json(200, [
        'applied' => ['needs' => $result['needs'], 'stock' => $result['stock']],
        'note' => $result['note'],
    ] + ($site === null ? [] : fc_colony_view($site)));

case 'colony':
    $caller = fc_api_colony_caller();
    $query = trim((string) ($_GET['site'] ?? $_GET['id'] ?? ''));

    if ($caller['system'] !== null) {
        // A token sees its own system and no further. With no argument it gets
        // every build there, which is the useful answer when a colony has eight
        // of them; with one, the search is narrowed but never widened.
        $matches = fc_colony_sites_in($caller['system']);
        if ($query !== '') {
            $matches = array_values(array_filter($matches, static fn(array $s) =>
                (string) $s['market_id'] === $query
                || stripos((string) $s['name'], $query) !== false));
        }
    } else {
        if ($query === '') {
            fc_json(400, ['error' => 'Pass site= with a construction site name, or id= with its MarketID.']);
        }
        $matches = fc_colony_search($query);
    }
    if ($matches === []) {
        fc_json(404, ['error' => 'No build by that name yet. It appears once somebody docked there reports it.']);
    }
    // Several sites can share a name across systems, and a caller that asked by
    // name deserves to be told rather than silently given the first one.
    if (count($matches) > 1) {
        fc_json(300, [
            'error' => 'More than one build matches that name.',
            'sites' => array_map(static fn(array $s) => [
                'marketId' => (string) $s['market_id'],
                'name' => $s['name'],
                'system' => $s['system'],
                'readAt' => $s['read_at'],
            ], $matches),
        ]);
    }

    fc_json(200, fc_colony_view($matches[0]));

case 'colony_invite':
    $caller = fc_api_colony_caller();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fc_json(405, ['error' => 'POST to this one.']);
    }

    $marketId = (int) ($_POST['marketId'] ?? ($_GET['marketId'] ?? 0));
    $site = $marketId > 0 ? fc_colony_site($marketId) : null;
    $system = $caller['system'] ?? ($site['system'] ?? null);

    if ($system === null || $system === '') {
        fc_json(400, [
            'error' => 'Pass marketId= for a build in the system you are inviting somebody to.',
        ]);
    }
    // Only somebody already hauling in a system may invite others to it. That
    // is the whole membership rule: there is no owner, because a colonisation
    // crew does not have one, and anybody who has done a run has as much
    // standing as whoever started it.
    if (!fc_colony_participates($system, $caller['hauler'])) {
        fc_json(403, ['error' => 'Do a run in ' . $system . ' before inviting anybody to it.']);
    }

    $minted = fc_colony_mint_token($system, $marketId ?: null, $caller['hauler'], $_GET['label'] ?? null);
    fc_json(200, [
        'token' => $minted['token'],
        'system' => $system,
        'sites' => count(fc_colony_sites_in($system)),
        // Said plainly, because it is the one thing about this that surprises
        // people: there is no way to look it up again.
        'note' => 'Covers every build in that system, including ones not started yet. '
            . 'Only the hash is stored, so copy it now — it cannot be shown again.',
    ]);

/*
 * Which Colony Planner this board has, so a copy can tell whether it is old.
 *
 * No credential, deliberately, and it is the one endpoint here that should not
 * want one. The planner works entirely from a commander's own journal with no
 * key at all -- the key is only for the carrier hold and for sharing a build --
 * so gating this would mean every solo user silently never learning that a new
 * version exists. A planner whose key was wrong could not update itself back
 * into working order either.
 *
 * It gives away a version string and a path to a zip that nginx already serves
 * to anyone, exactly as the EDMC plugin's download is served. There is nothing
 * here to protect.
 *
 * The cost of being open is answered rather than accepted: the parsed version is
 * cached against the archive's mtime, so a request opens the zip only when the
 * planner has actually been republished. Everything after that is one small
 * read.
 *
 * The version is read out of the file rather than recorded somewhere separate,
 * so publishing a new planner is copying one zip in. Two places to change is
 * one place to forget.
 */
case 'planner':
    $zip = FC_ROOT . '/assets/colony-planner.zip';
    if (!is_file($zip)) {
        fc_json(404, ['error' => 'This board does not host the planner.']);
    }

    $stamp = (int) filemtime($zip);
    $cached = fc_one("SELECT v FROM fc_meta WHERE k = 'planner_version'")['v'] ?? '';
    [$cachedStamp, $cachedVersion] = array_pad(explode(':', (string) $cached, 2), 2, '');

    if ((string) $stamp === $cachedStamp && $cachedVersion !== '') {
        $version = $cachedVersion;
    } else {
        // Only when the archive has actually changed. The zip is the one source
        // of truth for what version this board has; this just avoids re-reading
        // it for every caller in between publications.
        $version = null;
        $archive = new ZipArchive();
        if ($archive->open($zip) === true) {
            $source = $archive->getFromName('colony_planner.py');
            $archive->close();
            if (is_string($source) && preg_match('/^VERSION\s*=\s*"([^"]+)"/m', $source, $found)) {
                $version = $found[1];
            }
        }
        if ($version === null) {
            fc_json(500, ['error' => 'The planner archive has no readable version.']);
        }
        fc_exec(
            "INSERT INTO fc_meta (k, v) VALUES ('planner_version', :v)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            ['v' => $stamp . ':' . $version],
        );
    }

    fc_json(200, [
        'version' => $version,
        // Versioned by mtime for the same reason the plugin download is:
        // Cloudflare caches by URL, and a rebuilt zip under the same name would
        // go on being served from the edge long after it changed.
        'url' => '/fc/assets/colony-planner.zip?v=' . (int) filemtime($zip),
        'size' => (int) filesize($zip),
    ]);

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
        'actions' => ['ingest', 'carrier', 'carriers', 'colony', 'colony_report', 'colony_invite', 'planner', 'me'],
    ]);
}
