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
 * lib/costs.php, which reconstructs it) and the carrier's cargo manifest, for
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

require_once __DIR__ . '/capi.php';
require_once __DIR__ . '/webhooks.php';

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
    // Written in the *ship's* journal, not the carrier's, and it names no
    // carrier at all -- the plugin adds the MarketID it was docked at. Owner
    // only, because it moves someone's cargo about.
    'CargoTransfer',
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
    // object, so the line-by-line pass above finds nothing in it. Neither is a
    // Companion API /fleetcarrier payload, which has no `event` key at all --
    // that absence is how the two are told apart.
    if ($events === []) {
        $decoded = json_decode($text, true);
        if (fc_is_capi_payload($decoded)) {
            return fc_ingest_capi_report($decoded, $user, $filename, $source, strlen($text));
        }
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
        fc_fill_itinerary_bodies((int) $carrierId);
        fc_close_itinerary((int) $carrierId);
        // Reads state that is only settled now the whole file has been applied.
        fc_webhook_board_refresh((int) $carrierId);
    }
    fc_webhook_flush_after_response();

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

/**
 * Run a Companion API payload through the same reporting shape an upload of
 * journal lines produces, so callers do not care which they were handed.
 *
 * @return array{seen:int,applied:int,carriers:array<int,string>,notes:string[]}
 */
function fc_ingest_capi_report(array $data, array $user, string $filename, string $source, int $bytes): array
{
    $result = fc_ingest_capi($data, $user);

    $report = [
        'seen' => 1,
        'applied' => $result['applied'] ? 1 : 0,
        'carriers' => [],
        'notes' => $result['note'] === null ? [] : [$result['note']],
    ];
    if ($result['applied'] && $result['carrier_id'] !== null) {
        $report['carriers'][$result['carrier_id']] = $result['callsign'] ?? (string) $result['carrier_id'];
        fc_fill_itinerary_bodies($result['carrier_id']);
        fc_close_itinerary($result['carrier_id']);

        // The board simply reflects whatever is newest, so a Companion API
        // payload updates it exactly as a journal upload does -- and it is what
        // carries a jump made while the commander was offline, which the
        // journal never sees at all.
        fc_webhook_board_refresh($result['carrier_id']);
    }
    fc_webhook_flush_after_response();

    fc_exec(
        'INSERT INTO fc_uploads (user_id, source, filename, bytes, events_seen, events_applied, carriers_touched, ts)
         VALUES (:uid, :src, :file, :bytes, :seen, :applied, :carriers, UTC_TIMESTAMP())',
        [
            'uid' => $user['id'],
            'src' => $source,
            'file' => mb_substr($filename, 0, 190),
            'bytes' => $bytes,
            'seen' => 1,
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

/**
 * `$mineraloil_name;` → `mineraloil`. Leaves plain names alone.
 *
 * Folded to lower case because the two sources disagree: the journal writes
 * `int_fighterbay_size5_class1_free` and the Companion API writes
 * `Int_FighterBay_Size5_Class1_Free` for the same module. These strings are
 * keys, not display text — the readable name lives in loc_name — so the only
 * thing case buys here is two rows for one thing on any table whose collation
 * is not already case-insensitive.
 */
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
    return strtolower($s);
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
 * `$claim` is true for owner-only events, and a journal can no longer claim
 * anything with them. A journal is a text file its owner writes: nothing in
 * one distinguishes a commander's own carrier from an invented `CarrierStats`
 * naming somebody else's, so treating these events as proof of ownership meant
 * ownership was only ever an assertion. Authorising with Frontier is the one
 * proof available that does not rest on trusting the uploader, so claiming now
 * happens there and only there -- see fc_ingest_capi.
 *
 * Owner-only events still apply to a carrier you have already claimed, which
 * is what keeps journal uploads useful for everything the Companion API does
 * not report.
 */
function fc_carrier_for_write(int $id, array $user, bool $claim, array &$report): ?array
{
    $carrier = fc_carrier($id);

    if ($carrier === null) {
        // Created unowned whatever the event was. A row exists so that public
        // sightings still accumulate; ownership waits for Frontier.
        fc_exec(
            'INSERT INTO fc_carriers (id, owner_user_id, created_at, updated_at)
             VALUES (:id, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()',
            ['id' => $id],
        );
        $carrier = fc_carrier($id);
        if ($carrier === null || !$claim) {
            return $carrier;
        }
    }

    if ($claim && !fc_owns($user, $carrier)) {
        $note = $carrier['owner_user_id'] === null
            ? 'Carrier ' . ($carrier['callsign'] ?? $id) . ' is not claimed by any account yet. '
                . 'Connect your Frontier account to claim it — a journal cannot prove a carrier is yours.'
            : 'Carrier ' . ($carrier['callsign'] ?? $id) . ' belongs to another account; '
                . 'its owner-only events were ignored.';
        if (!in_array($note, $report['notes'], true)) {
            $report['notes'][] = $note;
        }
        return null;
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
            // Silently dropping this is how somebody ends up uploading the
            // same file repeatedly wondering why nothing happens. These files
            // are overwritten by whichever market was opened last, so the one
            // sitting on disk is very often a starport's.
            $station = $event['StationName'] ?? null;
            $note = $name . '.json is from '
                . ($station === null ? 'another station' : $station)
                . ', not one of your carriers. The game overwrites that file every time you open a '
                . strtolower($name) . ' screen, so open your own carrier\'s and upload it again.';
            if (!in_array($note, $report['notes'], true)) {
                $report['notes'][] = $note;
            }
            return false;
        }
    } else {
        // A public event about a carrier we have never heard of is only worth
        // a row if it names the thing. CarrierLocation gives an id, a system
        // and nothing else -- and turns up for squadron carriers a commander
        // merely flew past, which would fill the board with nameless entries.
        // Updates to a carrier we already know still go through.
        if (!$isOwnerEvent && !isset($event['StationName']) && fc_carrier($carrierId) === null) {
            return false;
        }

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
        'CargoTransfer' => fc_ev_cargo_transfer($carrier, $event, $ts),
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
    // `Total` is the reserve after the deposit -- the balance-after for a
    // tritium row, exactly as CarrierBalance is for a credit one. It was being
    // read for the carrier's fuel level and then thrown away, which left every
    // fuel line in the ledger with an empty balance column.
    fc_ledger_add(
        $id,
        $ts,
        'fuel',
        'Tritium deposited',
        isset($event['Amount']) ? (int) $event['Amount'] : null,
        't',
        isset($event['Total']) ? (int) $event['Total'] : null,
    );
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

    // Once the Companion API has shown us the real order book, journal
    // placements older than that reading are history and must not come back.
    // Nothing reports an order being *filled*, so re-uploading an old journal
    // would otherwise resurrect every trade the owner ever set up — which is
    // exactly what happened the first time these were cleared.
    if ($carrier['orders_at'] !== null && $ts !== null
        && strcmp((string) $carrier['orders_at'], $ts) > 0
    ) {
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

/**
 * Cargo moved between a ship and the carrier it is docked at.
 *
 * The one part of the hold the game reports as it happens. CarrierStats has the
 * tonnage but is only written when the owner opens the management screen, and
 * the Companion API has the full manifest but runs ten to fifteen minutes
 * behind -- so without this, moving 1,216 t of tritium off a carrier left the
 * board showing the old figure until one of those two happened to catch up.
 *
 * Applied as a delta rather than a reading, because a delta is all the event
 * is. That means it can drift: a transfer made with the plugin closed is never
 * seen, and the arithmetic here silently assumes it saw everything. The next
 * Companion API sync replaces the whole manifest and puts it right, which is
 * why this deliberately stops short of trying to be authoritative -- it is a
 * good guess for the fifteen minutes before the real answer arrives.
 */
function fc_ev_cargo_transfer(array $carrier, array $event, ?string $ts): bool
{
    $transfers = $event['Transfers'] ?? null;
    if ($ts === null || !is_array($transfers) || $transfers === []) {
        return false;
    }
    // Re-uploading an old journal must not re-apply moves already counted.
    if (fc_stale($carrier, 'cargo_journal_at', $ts)) {
        return false;
    }

    $id = (int) $carrier['id'];
    $net = 0;
    $applied = false;

    foreach ($transfers as $transfer) {
        if (!is_array($transfer)) {
            continue;
        }
        $commodity = fc_clean_symbol($transfer['Type'] ?? null);
        $count = isset($transfer['Count']) ? (int) $transfer['Count'] : 0;
        $direction = strtolower((string) ($transfer['Direction'] ?? ''));
        if ($commodity === '' || $count <= 0 || !in_array($direction, ['tocarrier', 'toship'], true)) {
            continue;
        }

        $delta = $direction === 'tocarrier' ? $count : -$count;
        $net += $delta;
        $applied = true;

        if ($delta > 0) {
            // A stack we have never seen has no value to record: the event
            // carries a count and nothing else. Left at zero rather than
            // guessed at, and corrected by the next sync.
            fc_exec(
                'INSERT INTO fc_cargo (carrier_id, commodity, stolen, loc_name, qty, value)
                 VALUES (:cid, :c, 0, :loc, :qty, 0)
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)',
                [
                    'cid' => $id,
                    'c' => mb_substr($commodity, 0, 64),
                    'loc' => $transfer['Type_Localised'] ?? null,
                    'qty' => $delta,
                ],
            );
        } else {
            // Scale the stack's worth with what is left of it. `value` is the
            // whole stack rather than a unit price, so leaving it alone would
            // have a half-empty hold still valued as a full one.
            // stolen = 0 on both sides of this. The hold keys stolen and clean
            // stacks separately, and the event says which commodity moved but
            // never which of the two -- so it is applied to the stack that
            // actually turns up on a carrier. Guessing wrong costs one sync.
            fc_exec(
                'UPDATE fc_cargo
                    SET value = CASE WHEN qty > 0 THEN CAST(value * GREATEST(qty + :d1, 0) / qty AS SIGNED) ELSE 0 END,
                        qty = GREATEST(qty + :d2, 0)
                  WHERE carrier_id = :cid AND commodity = :c AND stolen = 0',
                ['d1' => $delta, 'd2' => $delta, 'cid' => $id, 'c' => mb_substr($commodity, 0, 64)],
            );
            fc_exec(
                'DELETE FROM fc_cargo WHERE carrier_id = :cid AND commodity = :c AND stolen = 0 AND qty <= 0',
                ['cid' => $id, 'c' => mb_substr($commodity, 0, 64)],
            );
        }
    }

    if (!$applied) {
        return false;
    }

    $fields = ['cargo_journal_at' => $ts];

    // The summary has to move with the manifest, or the board shows a hold
    // whose contents disagree with its own total. Clamped at both ends: the
    // free space cannot go negative, and cannot exceed a capacity we may not
    // know yet.
    if ($carrier['space_cargo'] !== null) {
        $fields['space_cargo'] = max(0, (int) $carrier['space_cargo'] + $net);
    }
    if ($carrier['space_free'] !== null) {
        $free = (int) $carrier['space_free'] - $net;
        if ($carrier['capacity'] !== null) {
            $free = min($free, (int) $carrier['capacity']);
        }
        $fields['space_free'] = max(0, $free);
    }

    fc_update_carrier($id, $fields);
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

/**
 * How far apart two records of the same arrival can be and still be the same
 * arrival.
 *
 * The journal timestamps a jump when the client sees it land; the Companion
 * API reports Frontier's own arrivalTime. They differ by seconds. A carrier
 * cannot genuinely arrive in the same system twice inside ten minutes — even a
 * body-to-body hop needs fifteen minutes of preparation and a five minute
 * cooldown — so anything closer than this is one arrival seen twice.
 */
const FC_ARRIVAL_MERGE_SECONDS = 600;

/**
 * Record an arrival, folding it into an existing one if we already have it
 * from the other source.
 *
 * Neither source is complete on its own: the journal knows the body and the
 * co-ordinates, the Companion API knows the real departure time. Whichever
 * arrives second fills in what the first was missing rather than adding a
 * second row.
 *
 * @return int the id of the row this arrival lives in
 */
function fc_merge_arrival(
    int $carrierId,
    string $system,
    ?string $body,
    string $arrival,
    ?string $departure = null,
    ?int $systemAddress = null,
    ?float $x = null,
    ?float $y = null,
    ?float $z = null,
): int {
    $existing = fc_one(
        'SELECT * FROM fc_itinerary
          WHERE carrier_id = :cid AND system = :sys
            AND ABS(TIMESTAMPDIFF(SECOND, arrival_time, :ts)) <= :window
          ORDER BY ABS(TIMESTAMPDIFF(SECOND, arrival_time, :ts2)) ASC
          LIMIT 1',
        [
            'cid' => $carrierId, 'sys' => $system, 'ts' => $arrival,
            'ts2' => $arrival, 'window' => FC_ARRIVAL_MERGE_SECONDS,
        ],
    );

    if ($existing !== null) {
        // Only ever add detail; never blank out something already known.
        $fields = [];
        if ($body !== null && ($existing['body'] === null || $existing['body'] === '')) {
            $fields['body'] = $body;
        }
        if ($departure !== null && $existing['departure_time'] === null) {
            $fields['departure_time'] = $departure;
        }
        if ($systemAddress !== null && $existing['system_address'] === null) {
            $fields['system_address'] = $systemAddress;
        }
        if ($x !== null && $existing['x'] === null) {
            $fields['x'] = $x;
            $fields['y'] = $y;
            $fields['z'] = $z;
        }

        if ($fields !== []) {
            $sets = [];
            $params = ['id' => $existing['id']];
            foreach ($fields as $column => $value) {
                $sets[] = "`{$column}` = :{$column}";
                $params[$column] = $value;
            }
            fc_exec('UPDATE fc_itinerary SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        }

        return (int) $existing['id'];
    }

    fc_exec(
        'INSERT INTO fc_itinerary (carrier_id, system, body, system_address, x, y, z, arrival_time, departure_time)
         VALUES (:cid, :sys, :body, :addr, :x, :y, :z, :ts, :dep)
         ON DUPLICATE KEY UPDATE system = VALUES(system),
                                 body = COALESCE(VALUES(body), body),
                                 departure_time = COALESCE(departure_time, VALUES(departure_time))',
        [
            'cid' => $carrierId, 'sys' => $system, 'body' => $body,
            'addr' => $systemAddress, 'x' => $x, 'y' => $y, 'z' => $z,
            'ts' => $arrival, 'dep' => $departure,
        ],
    );

    $row = fc_one(
        'SELECT id FROM fc_itinerary WHERE carrier_id = :cid AND arrival_time = :ts',
        ['cid' => $carrierId, 'ts' => $arrival],
    );
    return (int) ($row['id'] ?? 0);
}

/** Record a journal arrival and close off whatever stop preceded it. */
function fc_record_arrival(int $carrierId, string $system, array $event, string $ts): void
{
    $x = $y = $z = null;
    if (isset($event['StarPos']) && is_array($event['StarPos']) && count($event['StarPos']) === 3) {
        [$x, $y, $z] = array_map('floatval', $event['StarPos']);
    }

    $id = fc_merge_arrival(
        $carrierId,
        $system,
        $event['Body'] ?? null,
        $ts,
        null,
        isset($event['SystemAddress']) ? (int) $event['SystemAddress'] : null,
        $x, $y, $z,
    );

    // Close earlier stops after the merge, so this arrival is not mistaken for
    // one of them and given a departure time of its own.
    fc_exec(
        'UPDATE fc_itinerary SET departure_time = :ts
          WHERE carrier_id = :cid AND departure_time IS NULL AND arrival_time < :ts2 AND id <> :id',
        ['ts' => $ts, 'cid' => $carrierId, 'ts2' => $ts, 'id' => $id],
    );
}

/**
 * Name the body on arrivals that arrived without one.
 *
 * Only CarrierJump carries a body name, and it is written solely when the
 * commander is aboard. A jump made while they were elsewhere reaches us either
 * as a bodiless CarrierLocation or through the Companion API's itinerary,
 * which never names bodies at all.
 *
 * CarrierJumpRequest does name it, though, and its DepartureTime is the moment
 * the carrier arrives -- the two are the same instant, not fifteen minutes
 * apart. So a scheduled jump to the right system at the right time supplies
 * the name nothing else recorded.
 */
function fc_fill_itinerary_bodies(int $carrierId): int
{
    $blank = fc_all(
        "SELECT id, system, arrival_time FROM fc_itinerary
          WHERE carrier_id = :id AND (body IS NULL OR body = '')",
        ['id' => $carrierId],
    );

    $filled = 0;
    foreach ($blank as $stop) {
        $jump = fc_one(
            "SELECT body FROM fc_jumps
              WHERE carrier_id = :cid AND system = :sys
                AND body IS NOT NULL AND body <> ''
                AND ABS(TIMESTAMPDIFF(SECOND, departure_time, :ts)) <= :window
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, departure_time, :ts2)) ASC
              LIMIT 1",
            [
                'cid' => $carrierId, 'sys' => $stop['system'], 'ts' => $stop['arrival_time'],
                'ts2' => $stop['arrival_time'], 'window' => FC_ARRIVAL_MERGE_SECONDS,
            ],
        );
        if ($jump === null) {
            continue;
        }
        fc_exec('UPDATE fc_itinerary SET body = :body WHERE id = :id',
            ['body' => $jump['body'], 'id' => $stop['id']]);
        $filled++;
    }

    return $filled;
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
        'SELECT id, system, arrival_time, departure_time FROM fc_itinerary
          WHERE carrier_id = :id ORDER BY arrival_time ASC',
        ['id' => $carrierId],
    );

    $count = count($stops);
    for ($i = 0; $i + 1 < $count; $i++) {
        // Only fill blanks. The Companion API supplies real departure times,
        // which are minutes earlier than the next arrival and should not be
        // rounded up to it.
        if ($stops[$i]['departure_time'] !== null) {
            continue;
        }
        fc_exec(
            'UPDATE fc_itinerary SET departure_time = :dep WHERE id = :id',
            ['dep' => $stops[$i + 1]['arrival_time'], 'id' => $stops[$i]['id']],
        );
    }

    // The newest stop is open if the carrier is still in that system, and we
    // know where the carrier is independently. Without this the last stop can
    // keep a departure it inherited from a duplicate row that has since been
    // folded into it, leaving a carrier that has demonstrably gone nowhere
    // showing no current location at all.
    if ($count === 0) {
        return;
    }
    $newest = $stops[$count - 1];
    $carrier = fc_carrier($carrierId);
    if ($carrier !== null
        && $carrier['system'] !== null
        && $carrier['system'] === $newest['system']
        && $newest['departure_time'] !== null
    ) {
        fc_exec('UPDATE fc_itinerary SET departure_time = NULL WHERE id = :id', ['id' => $newest['id']]);
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
        if (!fc_is_traded((int) ($item['Stock'] ?? 0), (int) ($item['Demand'] ?? 0))) {
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
        $cost = (int) ($item['BuyPrice'] ?? 0);
        if ($module === '' || !fc_is_stocked_module($module, $cost)) {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'module' => mb_substr($module, 0, 96),
            'loc' => fc_module_label($module),
            'cat' => fc_module_category($module),
            'cost' => $cost,
            'stock' => 1,
        ]);
    }

    fc_update_carrier($id, ['outfitting_at' => $ts]);
    return true;
}

/**
 * `hpt_pulselaser_fixed_small` → `Pulse Laser, Fixed, Small`.
 *
 * Frontier's symbols are the only name either source gives us, so this is
 * cosmetics on top of them rather than a real lookup table. `_free` on a
 * fighter bay is the game's own marker for the loaner variants that show up in
 * a carrier's outfitting list at no cost, and is worth keeping visible.
 */
function fc_module_label(string $symbol): string
{
    $s = preg_replace('/^(hpt|int|armour)_/i', '', strtolower($symbol)) ?? $symbol;
    $s = str_replace('_', ' ', $s);

    // "fighterbaymk2" has no word boundary before the mk, so the compound
    // spelling has to be dealt with before the general size/class split.
    $s = preg_replace('/fighterbaymk(\d+)/', 'fighter bay mk$1', $s) ?? $s;
    $s = preg_replace('/fighterbay/', 'fighter bay', $s) ?? $s;
    $s = preg_replace('/\b(size|class)(\d+)\b/', '$1 $2', $s) ?? $s;
    $s = preg_replace('/\bfree\b/', '(free)', $s) ?? $s;

    return mb_substr(ucwords($s), 0, 128);
}

/**
 * The journal writes these symbols lower case and the Companion API writes
 * them capitalised, so the prefix test has to ignore case or every module from
 * one of the two sources lands in "Other".
 */
/**
 * Whether a market row is actually being traded.
 *
 * A carrier's commodity list keeps every commodity it has ever been set up
 * for, whether or not anything is standing against it. Those leftovers come
 * back with no stock and no demand but still carry a price, so rendering them
 * shows a carrier offering to sell bauxite it does not have, at a price
 * nobody can pay. Stock or demand is what makes a listing real.
 */
function fc_is_traded(int $stock, int $demand): bool
{
    return $stock > 0 || $demand > 0;
}

/**
 * Whether an outfitting entry is real stock.
 *
 * A carrier's outfitting list always contains the `_free` fighter bay
 * variants at zero credits, whether or not anything is actually stocked. They
 * are not for sale and cannot be bought; they are how the game says the list
 * is empty. Showing six of them reads as inventory when it is the opposite.
 *
 * Both conditions are required: a `_free` suffix alone is not enough to
 * discard something that has a real price on it.
 */
function fc_is_stocked_module(string $symbol, int $cost): bool
{
    return !($cost === 0 && str_ends_with(strtolower($symbol), '_free'));
}

function fc_module_category(string $symbol): string
{
    $s = strtolower($symbol);
    return match (true) {
        str_starts_with($s, 'hpt_') => 'Hardpoint',
        str_starts_with($s, 'int_') => 'Internal',
        str_starts_with($s, 'armour_') => 'Armour',
        default => 'Other',
    };
}
