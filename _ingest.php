<?php

declare(strict_types=1);

/**
 * Turning Elite Dangerous journal files into carrier state.
 *
 * FCMS gets its data from Frontier's Companion API, which needs an OAuth
 * client registered with Frontier. We do not have one and cannot get one, so
 * this reads the player journal instead — the same files the game writes to
 * Saved Games, which the player already owns outright.
 *
 * Almost everything the Companion API exposes is in there:
 *
 *   CarrierStats            complete snapshot: crew, finance, space, fuel
 *   CarrierJump             arrivals, and the co-ordinates of the new system
 *   CarrierJumpRequest      the pending jump, with its departure time
 *   CarrierTradeOrder       standing buy and sell orders
 *   CarrierFinance          tax rates and balances
 *   Market/Shipyard/Outfitting.json   the commodity, ship and module lists
 *
 * The two things it cannot give us are the exact upkeep breakdown (see
 * _costs.php, which reconstructs it) and the carrier's cargo manifest, for
 * which the market snapshot's stock figures stand in.
 *
 * Only carrier-related events are read. Everything else in the journal —
 * where the commander has been, what they killed, who they talked to — is
 * ignored and never stored.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/**
 * Events that only ever appear in the carrier owner's own journal. Seeing one
 * is what claims an unowned carrier, and they are refused for a carrier that
 * somebody else already owns.
 */
const FC_OWNER_EVENTS = [
    'CarrierStats', 'CarrierBuy', 'CarrierFinance', 'CarrierBankTransfer',
    'CarrierCrewServices', 'CarrierTradeOrder', 'CarrierJumpRequest',
    'CarrierJumpCancelled', 'CarrierDepositFuel', 'CarrierDockingPermission',
    'CarrierNameChanged', 'CarrierNameChange', 'CarrierShipPack',
    'CarrierModulePack', 'CarrierDecommission', 'CarrierCancelDecommission',
];

/** Events anyone can contribute, because visitors see them too. */
const FC_PUBLIC_EVENTS = ['CarrierJump', 'CarrierLocation', 'Docked', 'Location'];

const FC_SNAPSHOT_EVENTS = ['Market', 'Shipyard', 'Outfitting'];

/**
 * @return array{seen:int,applied:int,carriers:array<int,string>,notes:string[]}
 */
function fc_ingest_text(string $text, array $user, string $filename, string $source = 'web'): array
{
    $report = ['seen' => 0, 'applied' => 0, 'carriers' => [], 'notes' => []];

    $events = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || !isset($decoded['event'])) {
            continue;
        }
        $events[] = $decoded;
    }

    // A Market.json / Shipyard.json / Outfitting.json is a single pretty-printed
    // object, so the line-by-line pass above finds nothing in it.
    if ($events === []) {
        $decoded = json_decode($text, true);
        if (is_array($decoded) && isset($decoded['event'])) {
            $events[] = $decoded;
        }
    }

    $report['seen'] = count($events);
    if ($events === []) {
        $report['notes'][] = 'No journal events found in ' . $filename . '.';
        return $report;
    }

    // Files can be uploaded in any order; state guards compare timestamps, so
    // apply oldest first and the guards do the right thing either way.
    usort($events, static function (array $a, array $b): int {
        return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
    });

    foreach ($events as $event) {
        if (fc_apply_event($event, $user, $report)) {
            $report['applied']++;
        }
    }

    foreach (array_keys($report['carriers']) as $carrierId) {
        fc_close_itinerary((int) $carrierId);
    }

    fc_exec(
        'INSERT INTO fc_uploads (user_id, source, filename, bytes, events_seen, events_applied, carriers_touched, ts)
         VALUES (:uid, :src, :file, :bytes, :seen, :applied, :carriers, UTC_TIMESTAMP())',
        [
            'uid' => $user['id'],
            'src' => $source,
            'file' => mb_substr($filename, 0, 190),
            'bytes' => strlen($text),
            'seen' => $report['seen'],
            'applied' => $report['applied'],
            'carriers' => mb_substr(implode(', ', $report['carriers']), 0, 190),
        ],
    );

    return $report;
}

/** ISO 8601 from the journal to a MySQL DATETIME in UTC. */
function fc_ts(mixed $iso): ?string
{
    if (!is_string($iso) || $iso === '') {
        return null;
    }
    $t = strtotime($iso);
    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}

/** `$mineraloil_name;` → `mineraloil`. Leaves plain names alone. */
function fc_clean_symbol(?string $raw): string
{
    if ($raw === null) {
        return '';
    }
    $s = trim($raw);
    if ($s !== '' && $s[0] === '$') {
        $s = substr($s, 1);
    }
    $s = preg_replace('/;$/', '', $s) ?? $s;
    $s = preg_replace('/_name$/i', '', $s) ?? $s;
    return $s;
}

/**
 * The carrier a snapshot or event belongs to.
 *
 * For fleet carriers Frontier uses the same number for CarrierID and MarketID,
 * which is what lets a visitor's CarrierJump (no CarrierID field) be matched to
 * the owner's CarrierStats.
 */
function fc_event_carrier_id(array $event): ?int
{
    foreach (['CarrierID', 'MarketID'] as $key) {
        if (isset($event[$key]) && is_numeric($event[$key])) {
            return (int) $event[$key];
        }
    }
    return null;
}

function fc_touch_carrier(array &$report, int $id, ?string $callsign): void
{
    $report['carriers'][$id] = $callsign ?? (string) $id;
}

/**
 * Create the carrier row if it is new, and return it.
 *
 * `$claim` is true for owner-only events: the uploader becomes the owner of a
 * carrier that has none. A carrier owned by somebody else is never reassigned.
 */
function fc_carrier_for_write(int $id, array $user, bool $claim, array &$report): ?array
{
    $carrier = fc_carrier($id);

    if ($carrier === null) {
        fc_exec(
            'INSERT INTO fc_carriers (id, owner_user_id, created_at, updated_at)
             VALUES (:id, :owner, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()',
            ['id' => $id, 'owner' => $claim ? $user['id'] : null],
        );
        return fc_carrier($id);
    }

    if ($claim) {
        if ($carrier['owner_user_id'] === null) {
            fc_exec(
                'UPDATE fc_carriers SET owner_user_id = :owner WHERE id = :id AND owner_user_id IS NULL',
                ['owner' => $user['id'], 'id' => $id],
            );
            $carrier = fc_carrier($id);
        } elseif ((int) $carrier['owner_user_id'] !== (int) $user['id'] && (int) $user['is_admin'] !== 1) {
            $note = 'Carrier ' . ($carrier['callsign'] ?? $id) . ' is already claimed by another account; '
                . 'its owner-only events were ignored.';
            if (!in_array($note, $report['notes'], true)) {
                $report['notes'][] = $note;
            }
            return null;
        }
    }

    return $carrier;
}

/**
 * Has this aspect of the carrier already been updated by something newer?
 *
 * Uploading an old journal after a recent one must not roll state backwards.
 */
function fc_stale(?array $carrier, string $column, ?string $ts): bool
{
    if ($ts === null) {
        return true;
    }
    $existing = $carrier[$column] ?? null;
    return $existing !== null && strcmp($existing, $ts) > 0;
}

function fc_ledger_add(int $carrierId, ?string $ts, string $kind, ?string $detail, ?int $amount, string $unit = 'cr', ?int $balance = null): void
{
    if ($ts === null) {
        return;
    }
    $hash = sha1(implode('|', [$carrierId, $ts, $kind, (string) $detail, (string) $amount]));
    fc_exec(
        'INSERT IGNORE INTO fc_ledger (carrier_id, ts, kind, detail, amount, unit, balance, dedupe_hash)
         VALUES (:cid, :ts, :kind, :detail, :amount, :unit, :balance, :hash)',
        [
            'cid' => $carrierId, 'ts' => $ts, 'kind' => $kind,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 190),
            'amount' => $amount, 'unit' => $unit, 'balance' => $balance, 'hash' => $hash,
        ],
    );
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

function fc_apply_event(array $event, array $user, array &$report): bool
{
    $name = (string) $event['event'];
    $ts = fc_ts($event['timestamp'] ?? null);

    $isOwnerEvent = in_array($name, FC_OWNER_EVENTS, true);
    $isPublic = in_array($name, FC_PUBLIC_EVENTS, true);
    $isSnapshot = in_array($name, FC_SNAPSHOT_EVENTS, true);

    if (!$isOwnerEvent && !$isPublic && !$isSnapshot) {
        return false;
    }

    // Docked/Location are ordinary events at ordinary stations most of the
    // time; only the fleet carrier variant is of any interest here.
    if (($name === 'Docked' || $name === 'Location') && ($event['StationType'] ?? '') !== 'FleetCarrier') {
        return false;
    }

    $carrierId = fc_event_carrier_id($event);
    if ($carrierId === null) {
        return false;
    }

    // Snapshots are matched by MarketID against a carrier that already exists
    // and belongs to the uploader. StationType would be the obvious test, but
    // only Market.json carries one -- Shipyard.json and Outfitting.json name
    // the station and nothing else, so keying on it would silently throw away
    // the owner's own shipyard while happily creating carrier rows for every
    // ordinary starport they ever docked at.
    if ($isSnapshot) {
        $carrier = fc_carrier($carrierId);
        if ($carrier === null || !fc_owns($user, $carrier)) {
            return false;
        }
    } else {
        $carrier = fc_carrier_for_write($carrierId, $user, $isOwnerEvent, $report);
        if ($carrier === null) {
            return false;
        }
    }

    $applied = match ($name) {
        'CarrierStats' => fc_ev_stats($carrier, $event, $ts),
        'CarrierBuy' => fc_ev_buy($carrier, $event, $ts),
        'CarrierNameChanged', 'CarrierNameChange' => fc_ev_rename($carrier, $event, $ts),
        'CarrierDockingPermission' => fc_ev_docking($carrier, $event, $ts),
        'CarrierDepositFuel' => fc_ev_fuel($carrier, $event, $ts),
        'CarrierBankTransfer' => fc_ev_bank($carrier, $event, $ts),
        'CarrierFinance' => fc_ev_finance($carrier, $event, $ts),
        'CarrierCrewServices' => fc_ev_crew($carrier, $event, $ts),
        'CarrierShipPack', 'CarrierModulePack' => fc_ev_pack($carrier, $event, $ts, $name),
        'CarrierTradeOrder' => fc_ev_trade_order($carrier, $event, $ts),
        'CarrierJumpRequest' => fc_ev_jump_request($carrier, $event, $ts),
        'CarrierJumpCancelled' => fc_ev_jump_cancelled($carrier, $event, $ts),
        'CarrierJump', 'CarrierLocation', 'Docked', 'Location' => fc_ev_location($carrier, $event, $ts, $name),
        'CarrierDecommission' => fc_ev_decommission($carrier, $event, $ts, true),
        'CarrierCancelDecommission' => fc_ev_decommission($carrier, $event, $ts, false),
        'Market' => fc_ev_market($carrier, $event, $ts),
        'Shipyard' => fc_ev_shipyard($carrier, $event, $ts),
        'Outfitting' => fc_ev_outfitting($carrier, $event, $ts),
        default => false,
    };

    if ($applied) {
        $fresh = fc_carrier($carrierId);
        fc_touch_carrier($report, $carrierId, $fresh['callsign'] ?? null);
    }

    return $applied;
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function fc_ev_stats(array $carrier, array $event, ?string $ts): bool
{
    if (fc_stale($carrier, 'stats_at', $ts)) {
        return false;
    }
    $id = (int) $carrier['id'];
    $space = $event['SpaceUsage'] ?? [];
    $finance = $event['Finance'] ?? [];

    $fields = [
        'callsign' => isset($event['Callsign']) ? strtoupper((string) $event['Callsign']) : $carrier['callsign'],
        'name' => $event['Name'] ?? $carrier['name'],
        'docking_access' => $event['DockingAccess'] ?? $carrier['docking_access'],
        'allow_notorious' => isset($event['AllowNotorious']) ? (int) (bool) $event['AllowNotorious'] : $carrier['allow_notorious'],
        'fuel_level' => isset($event['FuelLevel']) ? (int) $event['FuelLevel'] : $carrier['fuel_level'],
        'jump_range_curr' => $event['JumpRangeCurr'] ?? $carrier['jump_range_curr'],
        'jump_range_max' => $event['JumpRangeMax'] ?? $carrier['jump_range_max'],
        'pending_decommission' => (int) (bool) ($event['PendingDecommission'] ?? false),
        'capacity' => isset($space['TotalCapacity']) ? (int) $space['TotalCapacity'] : $carrier['capacity'],
        'space_crew' => isset($space['Crew']) ? (int) $space['Crew'] : null,
        'space_cargo' => isset($space['Cargo']) ? (int) $space['Cargo'] : null,
        'space_reserved' => isset($space['CargoSpaceReserved']) ? (int) $space['CargoSpaceReserved'] : null,
        'space_shippacks' => isset($space['ShipPacks']) ? (int) $space['ShipPacks'] : null,
        'space_modulepacks' => isset($space['ModulePacks']) ? (int) $space['ModulePacks'] : null,
        'space_free' => isset($space['FreeSpace']) ? (int) $space['FreeSpace'] : null,
        'market_id' => $carrier['market_id'] ?? $id,
        'stats_at' => $ts,
    ];

    // CarrierStats carries the finance block too, but a standalone
    // CarrierFinance event may be newer, so it gets its own guard.
    if ($finance !== [] && !fc_stale($carrier, 'finance_at', $ts)) {
        $fields += fc_finance_fields($finance);
        $fields['finance_at'] = $ts;
    }

    fc_update_carrier($id, $fields);

    if (isset($event['Crew']) && is_array($event['Crew'])) {
        // A full roster replaces what we had: a service that has been sold off
        // disappears from the list entirely, and should disappear here too.
        fc_exec('DELETE FROM fc_crew WHERE carrier_id = :id', ['id' => $id]);
        foreach ($event['Crew'] as $member) {
            if (!isset($member['CrewRole'])) {
                continue;
            }
            fc_exec(
                'INSERT INTO fc_crew (carrier_id, crew_role, activated, enabled, crew_name, updated_at)
                 VALUES (:cid, :role, :act, :en, :name, :ts)
                 ON DUPLICATE KEY UPDATE activated = VALUES(activated), enabled = VALUES(enabled),
                                         crew_name = VALUES(crew_name), updated_at = VALUES(updated_at)',
                [
                    'cid' => $id,
                    'role' => (string) $member['CrewRole'],
                    'act' => (int) (bool) ($member['Activated'] ?? false),
                    'en' => (int) (bool) ($member['Enabled'] ?? false),
                    'name' => $member['CrewName'] ?? null,
                    'ts' => $ts,
                ],
            );
        }
    }

    return true;
}

/** Shared by CarrierStats.Finance and the standalone CarrierFinance event. */
function fc_finance_fields(array $finance): array
{
    $fields = [
        'balance' => isset($finance['CarrierBalance']) ? (int) $finance['CarrierBalance'] : null,
        'reserve_balance' => isset($finance['ReserveBalance']) ? (int) $finance['ReserveBalance'] : null,
        'available_balance' => isset($finance['AvailableBalance']) ? (int) $finance['AvailableBalance'] : null,
        'reserve_percent' => isset($finance['ReservePercent']) ? (int) $finance['ReservePercent'] : null,
    ];

    // Early carriers had one tax rate; the live game sets it per service. Both
    // shapes turn up in old journals, so both are read.
    $fields['tax_rate'] = isset($finance['TaxRate']) ? (int) $finance['TaxRate'] : null;
    foreach (['refuel', 'repair', 'rearm', 'shipyard', 'outfitting'] as $service) {
        $key = 'TaxRate_' . $service;
        $fields['tax_' . $service] = isset($finance[$key]) ? (int) $finance[$key] : null;
    }

    return array_filter($fields, static fn($v) => $v !== null);
}

function fc_ev_buy(array $carrier, array $event, ?string $ts): bool
{
    $fields = ['market_id' => (int) $carrier['id']];
    if (isset($event['Callsign'])) {
        $fields['callsign'] = strtoupper((string) $event['Callsign']);
        $fields['name_at'] = $ts;
    }
    if (isset($event['Location']) && !fc_stale($carrier, 'location_at', $ts)) {
        $fields['system'] = (string) $event['Location'];
        $fields['system_address'] = isset($event['SystemAddress']) ? (int) $event['SystemAddress'] : null;
        $fields['location_at'] = $ts;
    }
    fc_update_carrier((int) $carrier['id'], $fields);
    fc_ledger_add((int) $carrier['id'], $ts, 'purchase', 'Carrier purchased', isset($event['Price']) ? -(int) $event['Price'] : null);
    return true;
}

function fc_ev_rename(array $carrier, array $event, ?string $ts): bool
{
    if (fc_stale($carrier, 'name_at', $ts)) {
        return false;
    }
    $fields = ['name_at' => $ts];
    if (isset($event['Name'])) {
        $fields['name'] = (string) $event['Name'];
    }
    if (isset($event['Callsign'])) {
        $fields['callsign'] = strtoupper((string) $event['Callsign']);
    }
    fc_update_carrier((int) $carrier['id'], $fields);
    return true;
}

function fc_ev_docking(array $carrier, array $event, ?string $ts): bool
{
    if (fc_stale($carrier, 'docking_at', $ts)) {
        return false;
    }
    fc_update_carrier((int) $carrier['id'], [
        'docking_access' => $event['DockingAccess'] ?? null,
        'allow_notorious' => (int) (bool) ($event['AllowNotorious'] ?? false),
        'docking_at' => $ts,
    ]);
    return true;
}

function fc_ev_fuel(array $carrier, array $event, ?string $ts): bool
{
    $id = (int) $carrier['id'];
    fc_ledger_add($id, $ts, 'fuel', 'Tritium deposited', isset($event['Amount']) ? (int) $event['Amount'] : null, 't');
    if (fc_stale($carrier, 'fuel_at', $ts) || !isset($event['Total'])) {
        return true;
    }
    fc_update_carrier($id, ['fuel_level' => (int) $event['Total'], 'fuel_at' => $ts]);
    return true;
}

function fc_ev_bank(array $carrier, array $event, ?string $ts): bool
{
    $id = (int) $carrier['id'];
    $deposit = isset($event['Deposit']) ? (int) $event['Deposit'] : 0;
    $withdraw = isset($event['Withdraw']) ? (int) $event['Withdraw'] : 0;
    $balance = isset($event['CarrierBalance']) ? (int) $event['CarrierBalance'] : null;

    fc_ledger_add(
        $id,
        $ts,
        $deposit > 0 ? 'deposit' : 'withdrawal',
        $deposit > 0 ? 'Transfer to carrier bank' : 'Transfer to commander',
        $deposit > 0 ? $deposit : -$withdraw,
        'cr',
        $balance,
    );

    if ($balance !== null && !fc_stale($carrier, 'finance_at', $ts)) {
        fc_update_carrier($id, ['balance' => $balance, 'finance_at' => $ts]);
    }
    return true;
}

function fc_ev_finance(array $carrier, array $event, ?string $ts): bool
{
    if (fc_stale($carrier, 'finance_at', $ts)) {
        return false;
    }
    $fields = fc_finance_fields($event);
    $fields['finance_at'] = $ts;
    fc_update_carrier((int) $carrier['id'], $fields);
    return true;
}

function fc_ev_crew(array $carrier, array $event, ?string $ts): bool
{
    $role = $event['CrewRole'] ?? null;
    if ($role === null) {
        return false;
    }
    $id = (int) $carrier['id'];
    $operation = (string) ($event['Operation'] ?? '');

    // Activate installs and starts a service; Deactivate sells it off. Pause
    // and Resume only toggle whether it runs, which is the difference between
    // the full weekly cost and the retainer.
    [$activated, $enabled] = match ($operation) {
        'Activate' => [1, 1],
        'Deactivate' => [0, 0],
        'Pause' => [null, 0],
        'Resume' => [null, 1],
        default => [null, null],
    };

    $existing = fc_one(
        'SELECT * FROM fc_crew WHERE carrier_id = :cid AND crew_role = :role',
        ['cid' => $id, 'role' => $role],
    );
    if ($existing !== null && $existing['updated_at'] !== null && $ts !== null
        && strcmp((string) $existing['updated_at'], $ts) > 0) {
        return false;
    }

    fc_exec(
        'INSERT INTO fc_crew (carrier_id, crew_role, activated, enabled, crew_name, updated_at)
         VALUES (:cid, :role, :act, :en, :name, :ts)
         ON DUPLICATE KEY UPDATE activated = VALUES(activated), enabled = VALUES(enabled),
                                 crew_name = COALESCE(VALUES(crew_name), crew_name),
                                 updated_at = VALUES(updated_at)',
        [
            'cid' => $id,
            'role' => (string) $role,
            'act' => $activated ?? (int) ($existing['activated'] ?? 1),
            'en' => $enabled ?? (int) ($existing['enabled'] ?? 1),
            'name' => $event['CrewName'] ?? null,
            'ts' => $ts,
        ],
    );

    return true;
}

function fc_ev_pack(array $carrier, array $event, ?string $ts, string $eventName): bool
{
    $kind = $eventName === 'CarrierShipPack' ? 'ship pack' : 'module pack';
    $operation = (string) ($event['Operation'] ?? '');
    $cost = isset($event['Cost']) ? (int) $event['Cost'] : null;
    // Selling a pack refunds credits, so the sign follows the operation.
    if ($cost !== null && str_starts_with($operation, 'Buy')) {
        $cost = -$cost;
    }
    $detail = trim($operation . ' ' . $kind . ': ' . ($event['PackTheme'] ?? '?') . ' tier ' . ($event['PackTier'] ?? '?'));
    fc_ledger_add((int) $carrier['id'], $ts, 'pack', $detail, $cost);
    return true;
}

function fc_ev_trade_order(array $carrier, array $event, ?string $ts): bool
{
    $id = (int) $carrier['id'];
    $commodity = fc_clean_symbol($event['Commodity'] ?? null);
    if ($commodity === '') {
        return false;
    }
    $blackMarket = (int) (bool) ($event['BlackMarket'] ?? false);

    if (!empty($event['CancelTrade'])) {
        fc_exec(
            'DELETE FROM fc_orders WHERE carrier_id = :cid AND commodity = :c AND black_market = :bm',
            ['cid' => $id, 'c' => $commodity, 'bm' => $blackMarket],
        );
        return true;
    }

    $purchase = isset($event['PurchaseOrder']) ? (int) $event['PurchaseOrder'] : 0;
    $sale = isset($event['SaleOrder']) ? (int) $event['SaleOrder'] : 0;
    if ($purchase <= 0 && $sale <= 0) {
        return false;
    }

    fc_exec(
        'INSERT INTO fc_orders (carrier_id, commodity, black_market, loc_name, kind, amount, price, updated_at)
         VALUES (:cid, :c, :bm, :loc, :kind, :amount, :price, :ts)
         ON DUPLICATE KEY UPDATE loc_name = VALUES(loc_name), kind = VALUES(kind),
                                 amount = VALUES(amount), price = VALUES(price),
                                 updated_at = VALUES(updated_at)',
        [
            'cid' => $id,
            'c' => $commodity,
            'bm' => $blackMarket,
            'loc' => $event['Commodity_Localised'] ?? null,
            'kind' => $purchase > 0 ? 'buy' : 'sell',
            'amount' => $purchase > 0 ? $purchase : $sale,
            'price' => isset($event['Price']) ? (int) $event['Price'] : 0,
            'ts' => $ts,
        ],
    );
    return true;
}

function fc_ev_jump_request(array $carrier, array $event, ?string $ts): bool
{
    $departure = fc_ts($event['DepartureTime'] ?? null);
    if ($departure === null) {
        // Pre-2021 journals omitted DepartureTime. Record the request against
        // its own timestamp so the destination is still visible.
        $departure = $ts;
    }
    if ($departure === null) {
        return false;
    }
    fc_exec(
        'INSERT INTO fc_jumps (carrier_id, system, body, departure_time, status, created_at)
         VALUES (:cid, :sys, :body, :dep, :status, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE system = VALUES(system), body = VALUES(body), status = VALUES(status)',
        [
            'cid' => (int) $carrier['id'],
            'sys' => $event['SystemName'] ?? null,
            'body' => $event['Body'] ?? null,
            'dep' => $departure,
            'status' => 'scheduled',
        ],
    );
    return true;
}

function fc_ev_jump_cancelled(array $carrier, array $event, ?string $ts): bool
{
    // The event names no destination, so the most recent still-scheduled jump
    // is the one that was called off.
    fc_exec(
        "UPDATE fc_jumps SET status = 'cancelled'
          WHERE carrier_id = :cid AND status = 'scheduled'
          ORDER BY departure_time DESC LIMIT 1",
        ['cid' => (int) $carrier['id']],
    );
    return true;
}

function fc_ev_location(array $carrier, array $event, ?string $ts, string $eventName): bool
{
    $id = (int) $carrier['id'];
    $system = $event['StarSystem'] ?? ($event['SystemName'] ?? null);
    if ($system === null) {
        return false;
    }

    // History accumulates whatever order it arrives in. A jump from two years
    // ago is still a real arrival even though it must not move the carrier's
    // current position -- which is exactly what a backfill is full of, and
    // guarding this behind the staleness check threw all of it away.
    if ($eventName === 'CarrierJump' && $ts !== null) {
        fc_record_arrival($id, (string) $system, $event, $ts);
        fc_exec(
            "UPDATE fc_jumps SET status = 'completed'
              WHERE carrier_id = :cid AND status = 'scheduled' AND departure_time <= :ts",
            ['cid' => $id, 'ts' => $ts],
        );
    }

    if (fc_stale($carrier, 'location_at', $ts)) {
        // Something newer already says where the carrier is. Still counts as
        // applied if it contributed an arrival.
        return $eventName === 'CarrierJump';
    }

    $fields = [
        'system' => (string) $system,
        'body' => $event['Body'] ?? null,
        'body_id' => isset($event['BodyID']) ? (int) $event['BodyID'] : null,
        'system_address' => isset($event['SystemAddress']) ? (int) $event['SystemAddress'] : null,
        'location_at' => $ts,
        'market_id' => $carrier['market_id'] ?? $id,
    ];

    // StarPos is the only source of galactic co-ordinates we get for free; it
    // is on CarrierJump but not on CarrierLocation.
    if (isset($event['StarPos']) && is_array($event['StarPos']) && count($event['StarPos']) === 3) {
        [$fields['x'], $fields['y'], $fields['z']] = array_map('floatval', $event['StarPos']);
    }
    if (isset($event['StationName']) && $carrier['callsign'] === null) {
        $fields['callsign'] = strtoupper((string) $event['StationName']);
    }

    fc_update_carrier($id, $fields);
    return true;
}

/** Close off the previous stop and open a new one. */
function fc_record_arrival(int $carrierId, string $system, array $event, string $ts): void
{
    fc_exec(
        'UPDATE fc_itinerary SET departure_time = :ts
          WHERE carrier_id = :cid AND departure_time IS NULL AND arrival_time < :ts2',
        ['ts' => $ts, 'cid' => $carrierId, 'ts2' => $ts],
    );

    $x = $y = $z = null;
    if (isset($event['StarPos']) && is_array($event['StarPos']) && count($event['StarPos']) === 3) {
        [$x, $y, $z] = array_map('floatval', $event['StarPos']);
    }

    fc_exec(
        'INSERT INTO fc_itinerary (carrier_id, system, body, system_address, x, y, z, arrival_time)
         VALUES (:cid, :sys, :body, :addr, :x, :y, :z, :ts)
         ON DUPLICATE KEY UPDATE system = VALUES(system), body = VALUES(body)',
        [
            'cid' => $carrierId,
            'sys' => $system,
            'body' => $event['Body'] ?? null,
            'addr' => isset($event['SystemAddress']) ? (int) $event['SystemAddress'] : null,
            'x' => $x, 'y' => $y, 'z' => $z,
            'ts' => $ts,
        ],
    );
}

/**
 * Make each stop's departure the next stop's arrival, leaving only the latest
 * one open.
 *
 * The journal never records a carrier leaving, so a departure is only ever
 * inferred from the next arrival. Doing that as events stream in works while
 * they arrive in order, but a backfill inserts arrivals *behind* ones already
 * stored, which would otherwise leave a trail of stops the carrier apparently
 * never left. Cheap to just re-derive the whole column afterwards.
 */
function fc_close_itinerary(int $carrierId): void
{
    $stops = fc_all(
        'SELECT id, arrival_time, departure_time FROM fc_itinerary
          WHERE carrier_id = :id ORDER BY arrival_time ASC',
        ['id' => $carrierId],
    );

    $count = count($stops);
    for ($i = 0; $i < $count; $i++) {
        $wanted = $i + 1 < $count ? $stops[$i + 1]['arrival_time'] : null;
        if ($stops[$i]['departure_time'] === $wanted) {
            continue;
        }
        fc_exec(
            'UPDATE fc_itinerary SET departure_time = :dep WHERE id = :id',
            ['dep' => $wanted, 'id' => $stops[$i]['id']],
        );
    }
}

function fc_ev_decommission(array $carrier, array $event, ?string $ts, bool $pending): bool
{
    fc_update_carrier((int) $carrier['id'], ['pending_decommission' => $pending ? 1 : 0]);
    if ($pending) {
        fc_ledger_add(
            (int) $carrier['id'],
            $ts,
            'decommission',
            'Decommission scheduled',
            isset($event['ScrapRefund']) ? (int) $event['ScrapRefund'] : null,
        );
    }
    return true;
}

// ---------------------------------------------------------------------------
// Snapshot files
// ---------------------------------------------------------------------------

function fc_ev_market(array $carrier, array $event, ?string $ts): bool
{
    $items = $event['Items'] ?? null;
    if (!is_array($items)) {
        // The inline journal `Market` event names the station but carries no
        // item list — that only exists in Market.json.
        return false;
    }
    if (fc_stale($carrier, 'market_at', $ts)) {
        return false;
    }
    $id = (int) $carrier['id'];

    fc_exec('DELETE FROM fc_market WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_market (carrier_id, commodity, loc_name, category, stock, demand, buy_price, sell_price, mean_price)
         VALUES (:cid, :c, :loc, :cat, :stock, :demand, :buy, :sell, :mean)
         ON DUPLICATE KEY UPDATE stock = VALUES(stock), demand = VALUES(demand)',
    );

    foreach ($items as $item) {
        $commodity = fc_clean_symbol($item['Name'] ?? null);
        if ($commodity === '') {
            continue;
        }
        $category = fc_clean_symbol($item['Category'] ?? null);
        $category = preg_replace('/^MARKET_category_/i', '', $category) ?? $category;
        if (strcasecmp($category, 'NonMarketable') === 0) {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'c' => mb_substr($commodity, 0, 64),
            'loc' => $item['Name_Localised'] ?? null,
            'cat' => $item['Category_Localised'] ?? ucfirst(str_replace('_', ' ', $category)),
            'stock' => (int) ($item['Stock'] ?? 0),
            'demand' => (int) ($item['Demand'] ?? 0),
            'buy' => (int) ($item['BuyPrice'] ?? 0),
            'sell' => (int) ($item['SellPrice'] ?? 0),
            'mean' => (int) ($item['MeanPrice'] ?? 0),
        ]);
    }

    fc_update_carrier($id, ['market_at' => $ts, 'market_id' => $id]);
    return true;
}

function fc_ev_shipyard(array $carrier, array $event, ?string $ts): bool
{
    $list = $event['PriceList'] ?? null;
    if (!is_array($list) || fc_stale($carrier, 'shipyard_at', $ts)) {
        return false;
    }
    $id = (int) $carrier['id'];

    fc_exec('DELETE FROM fc_shipyard WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_shipyard (carrier_id, ship, loc_name, base_value, stock)
         VALUES (:cid, :ship, :loc, :value, :stock)
         ON DUPLICATE KEY UPDATE base_value = VALUES(base_value)',
    );

    foreach ($list as $item) {
        $ship = fc_clean_symbol($item['ShipType'] ?? null);
        if ($ship === '') {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'ship' => mb_substr($ship, 0, 64),
            'loc' => $item['ShipType_Localised'] ?? ucfirst($ship),
            'value' => (int) ($item['ShipPrice'] ?? 0),
            // The journal's shipyard list has no stock column; the presence of
            // a row is itself the "in stock" signal.
            'stock' => 1,
        ]);
    }

    fc_update_carrier($id, ['shipyard_at' => $ts]);
    return true;
}

function fc_ev_outfitting(array $carrier, array $event, ?string $ts): bool
{
    $items = $event['Items'] ?? null;
    if (!is_array($items) || fc_stale($carrier, 'outfitting_at', $ts)) {
        return false;
    }
    $id = (int) $carrier['id'];

    fc_exec('DELETE FROM fc_outfitting WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_outfitting (carrier_id, module, loc_name, category, cost, stock)
         VALUES (:cid, :module, :loc, :cat, :cost, :stock)
         ON DUPLICATE KEY UPDATE cost = VALUES(cost)',
    );

    foreach ($items as $item) {
        $module = fc_clean_symbol($item['Name'] ?? null);
        if ($module === '') {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'module' => mb_substr($module, 0, 96),
            'loc' => fc_module_label($module),
            'cat' => fc_module_category($module),
            'cost' => (int) ($item['BuyPrice'] ?? 0),
            'stock' => 1,
        ]);
    }

    fc_update_carrier($id, ['outfitting_at' => $ts]);
    return true;
}

/** `hpt_pulselaser_fixed_small` → `Pulselaser Fixed Small`. */
function fc_module_label(string $symbol): string
{
    $s = preg_replace('/^(hpt|int|armour)_/i', '', $symbol) ?? $symbol;
    $s = str_replace('_', ' ', $s);
    return mb_substr(ucwords($s), 0, 128);
}

function fc_module_category(string $symbol): string
{
    return match (true) {
        str_starts_with($symbol, 'hpt_') => 'Hardpoint',
        str_starts_with($symbol, 'int_') => 'Internal',
        str_starts_with($symbol, 'armour_') => 'Armour',
        default => 'Other',
    };
}
