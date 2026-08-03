<?php

declare(strict_types=1);

/**
 * Reading Frontier's Companion API `/fleetcarrier` payload.
 *
 * We never talk to Frontier ourselves — that needs a client id issued to an
 * approved application, and its refresh tokens expire 25 days after the user
 * authorises, so every owner would have to log in again roughly monthly. That
 * is the dependency this whole app was written to avoid.
 *
 * EDMarketConnector already holds an approved client id and already queries
 * this endpoint, and it hands the result to plugins through `capi_fleetcarrier`.
 * So the CarrierOps plugin forwards the payload here and we parse it. No
 * registration, no expiry, no login to break.
 *
 * What this adds over the journal:
 *
 *   finance.coreCost / servicesCost   the real upkeep, not our reconstruction
 *   cargo                             the hold, which the journal never reports
 *   market / ships / modules          without opening those screens in game
 *   itinerary.completed               arrivals with real visit durations
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/**
 * Does this decoded JSON look like a /fleetcarrier response?
 *
 * It has no `event` key, so the journal parser finds nothing in it; this is
 * what tells the two apart.
 */
function fc_is_capi_payload(mixed $data): bool
{
    return is_array($data)
        && !isset($data['event'])
        && isset($data['name'])
        && is_array($data['name'])
        && (isset($data['name']['callsign']) || isset($data['name']['vanityName']));
}

/**
 * Carrier names come back hex-encoded UTF-8. An unregistered carrier has no
 * decodable name at all, which is not an error worth surfacing.
 */
function fc_capi_name(mixed $hex): ?string
{
    if (!is_string($hex) || $hex === '' || !ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
        return null;
    }
    $decoded = @hex2bin($hex);
    if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
        return null;
    }
    $decoded = trim($decoded);
    return $decoded === '' ? null : mb_substr($decoded, 0, 128);
}

/**
 * Apply a /fleetcarrier payload.
 *
 * @return array{applied:bool,carrier_id:?int,callsign:?string,note:?string}
 */
function fc_ingest_capi(array $data, array $user, ?string $ts = null): array
{
    $ts ??= gmdate('Y-m-d H:i:s');
    $blank = ['applied' => false, 'carrier_id' => null, 'callsign' => null, 'note' => null];

    // market.id is the MarketID, which for a carrier is also its CarrierID.
    $carrierId = null;
    foreach ([$data['carrierId'] ?? null, $data['market']['id'] ?? null] as $candidate) {
        if (is_numeric($candidate)) {
            $carrierId = (int) $candidate;
            break;
        }
    }

    $callsign = isset($data['name']['callsign']) ? strtoupper((string) $data['name']['callsign']) : null;

    // Fall back to the callsign when the payload has no usable id, so an odd
    // response still lands on the right carrier rather than creating a new one.
    if ($carrierId === null && $callsign !== null) {
        $existing = fc_carrier_by_callsign($callsign);
        if ($existing !== null) {
            $carrierId = (int) $existing['id'];
        }
    }
    if ($carrierId === null) {
        return $blank + ['note' => 'The carrier data had no id or callsign in it.'];
    }

    $carrier = fc_carrier($carrierId);

    // This is the only path that may claim a carrier, because it is the only
    // one where the claim is backed by anything: the payload was fetched with
    // a token Frontier issued to this account, either by the EDMC plugin or by
    // our own authorisation. The customer_id is recorded alongside so the
    // claim can be traced back to the Frontier account that made it.
    $customerId = fc_one(
        'SELECT customer_id FROM fc_capi_tokens WHERE user_id = :u',
        ['u' => $user['id']],
    )['customer_id'] ?? null;

    if ($carrier === null) {
        fc_exec(
            'INSERT INTO fc_carriers (id, owner_user_id, owner_customer_id, created_at, updated_at)
             VALUES (:id, :owner, :cust, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()',
            ['id' => $carrierId, 'owner' => $user['id'], 'cust' => $customerId],
        );
        $carrier = fc_carrier($carrierId);
    } elseif ($carrier['owner_user_id'] === null) {
        fc_exec(
            'UPDATE fc_carriers SET owner_user_id = :owner, owner_customer_id = :cust
              WHERE id = :id AND owner_user_id IS NULL',
            ['owner' => $user['id'], 'cust' => $customerId, 'id' => $carrierId],
        );
        $carrier = fc_carrier($carrierId);
    } elseif (fc_owns($user, $carrier) && $carrier['owner_customer_id'] === null && $customerId !== null) {
        // Claimed before the Frontier link existed, or before this column did.
        // The owner is fetching it with their own token right now, which is the
        // same proof a fresh claim would rest on, so record it.
        fc_exec(
            'UPDATE fc_carriers SET owner_customer_id = :cust WHERE id = :id AND owner_customer_id IS NULL',
            ['cust' => $customerId, 'id' => $carrierId],
        );
        $carrier = fc_carrier($carrierId);
    } elseif (!fc_owns($user, $carrier)) {
        // This payload only exists because someone authorised Frontier as the
        // owner, but claiming is still ours to police.
        return $blank + [
            'carrier_id' => $carrierId,
            'callsign' => $carrier['callsign'],
            'note' => 'Carrier ' . ($carrier['callsign'] ?? $carrierId)
                . ' is already claimed by another account; its Companion API data was ignored.',
        ];
    }

    if ($carrier === null) {
        return $blank;
    }

    // A payload older than what we already have is worth nothing.
    if ($carrier['capi_at'] !== null && strcmp((string) $carrier['capi_at'], $ts) > 0) {
        return $blank + ['carrier_id' => $carrierId, 'callsign' => $carrier['callsign']];
    }

    fc_capi_apply_carrier($carrierId, $carrier, $data, $ts, $callsign);
    fc_capi_apply_services($carrierId, $data, $ts);
    fc_capi_apply_cargo($carrierId, $data, $ts);
    fc_capi_apply_market($carrierId, $data, $ts);
    fc_capi_apply_orders($carrierId, $data, $ts);
    fc_capi_apply_shipyard($carrierId, $data, $ts);
    fc_capi_apply_outfitting($carrierId, $data, $ts);
    fc_capi_apply_itinerary($carrierId, $data);

    $fresh = fc_carrier($carrierId);
    return [
        'applied' => true,
        'carrier_id' => $carrierId,
        'callsign' => $fresh['callsign'] ?? $callsign,
        'note' => null,
    ];
}

function fc_capi_apply_carrier(int $id, array $carrier, array $data, string $ts, ?string $callsign): void
{
    $finance = is_array($data['finance'] ?? null) ? $data['finance'] : [];
    $itinerary = is_array($data['itinerary'] ?? null) ? $data['itinerary'] : [];

    $fields = ['capi_at' => $ts];

    if ($callsign !== null) {
        $fields['callsign'] = $callsign;
    }
    $name = fc_capi_name($data['name']['vanityName'] ?? null);
    if ($name !== null) {
        $fields['name'] = $name;
        $fields['name_at'] = $ts;
    }

    // The journal is the better source of position -- it is written the moment
    // the carrier arrives, while this is whatever the last CAPI query saw. Only
    // fill it in when it is genuinely newer.
    //
    // Note what `location_at` then means: when we last *heard* the position,
    // not when the carrier got there. It is a staleness guard, not an arrival
    // time. Anything wanting the latter reads the open itinerary stop.
    if (isset($data['currentStarSystem']) && !fc_stale($carrier, 'location_at', $ts)) {
        $fields['system'] = (string) $data['currentStarSystem'];
        $fields['location_at'] = $ts;
    }

    foreach ([
        'fuel_level' => 'fuel',
        'state' => 'state',
        'theme' => 'theme',
        'docking_access' => 'dockingAccess',
    ] as $column => $key) {
        if (isset($data[$key])) {
            $fields[$column] = $column === 'fuel_level' ? (int) $data[$key] : (string) $data[$key];
        }
    }
    if (isset($data['notoriousAccess'])) {
        $fields['allow_notorious'] = (int) (bool) $data['notoriousAccess'];
        $fields['docking_at'] = $ts;
    }

    if ($finance !== []) {
        foreach ([
            'balance' => 'balance',
            'taxation' => 'taxation',
            'core_cost' => 'coreCost',
            'services_cost' => 'servicesCost',
            'jumps_cost' => 'jumpsCost',
            'num_jumps' => 'numJumps',
        ] as $column => $key) {
            $source = $column === 'balance' ? $data : $finance;
            if (isset($source[$key]) && is_numeric($source[$key])) {
                $fields[$column] = (int) $source[$key];
            }
        }
        // `taxation` is sometimes a per-service map rather than a single rate.
        if (is_array($finance['taxation'] ?? null)) {
            unset($fields['taxation']);
            foreach (['refuel', 'repair', 'rearm', 'shipyard', 'outfitting'] as $service) {
                if (isset($finance['taxation'][$service]) && is_numeric($finance['taxation'][$service])) {
                    $fields['tax_' . $service] = (int) $finance['taxation'][$service];
                }
            }
        }
        $fields['finance_at'] = $ts;
    }

    if (isset($itinerary['totalDistanceJumpedLY']) && is_numeric($itinerary['totalDistanceJumpedLY'])) {
        $fields['total_distance_jumped'] = (float) $itinerary['totalDistanceJumpedLY'];
    }
    if (isset($data['market']['id']) && is_numeric($data['market']['id'])) {
        $fields['market_id'] = (int) $data['market']['id'];
    }

    fc_update_carrier($id, $fields);
}

/**
 * The services map says which services exist and whether each is running.
 *
 * It carries no crew names, so an existing roster from CarrierStats keeps
 * them; this only corrects the installed/running flags.
 */
function fc_capi_apply_services(int $id, array $data, string $ts): void
{
    $services = $data['market']['services'] ?? null;
    if (!is_array($services)) {
        return;
    }

    // CAPI service keys to the CrewRole names the journal and _costs.php use.
    static $roles = [
        'refuel' => 'Refuel',
        'repair' => 'Repair',
        'rearm' => 'Rearm',
        'shipyard' => 'Shipyard',
        'outfitting' => 'Outfitting',
        'blackmarket' => 'BlackMarket',
        'voucherredemption' => 'VoucherRedemption',
        'exploration' => 'Exploration',
        'bartender' => 'Bartender',
        'vistagenomics' => 'VistaGenomics',
        'pioneersupplies' => 'PioneerSupplies',
        'commodities' => 'Commodities',
        'carrierfuel' => 'CarrierFuel',
    ];

    foreach ($services as $key => $value) {
        $role = $roles[strtolower((string) $key)] ?? null;
        if ($role === null) {
            continue;
        }
        $state = strtolower((string) $value);
        // 'ok' is running, 'suspended' is installed but paused, anything else
        // means it is not there.
        $installed = in_array($state, ['ok', 'suspended'], true) ? 1 : 0;
        $running = $state === 'ok' ? 1 : 0;

        fc_exec(
            'INSERT INTO fc_crew (carrier_id, crew_role, activated, enabled, crew_name, updated_at)
             VALUES (:cid, :role, :act, :en, NULL, :ts)
             ON DUPLICATE KEY UPDATE activated = VALUES(activated), enabled = VALUES(enabled),
                                     updated_at = VALUES(updated_at)',
            ['cid' => $id, 'role' => $role, 'act' => $installed, 'en' => $running, 'ts' => $ts],
        );
    }
}

function fc_capi_apply_cargo(int $id, array $data, string $ts): void
{
    $cargo = $data['cargo'] ?? null;
    if (!is_array($cargo)) {
        return;
    }

    fc_exec('DELETE FROM fc_cargo WHERE carrier_id = :id', ['id' => $id]);
    // `value` is the worth of the whole stack, not a unit price -- 16,690 t of
    // tritium comes back as one figure of about 815 million, not 48,838. Both
    // columns therefore accumulate when stacks are merged.
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_cargo (carrier_id, commodity, stolen, loc_name, qty, value)
         VALUES (:cid, :c, :stolen, :loc, :qty, :value)
         ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), value = value + VALUES(value)'
    );

    foreach ($cargo as $item) {
        $commodity = fc_clean_symbol($item['commodity'] ?? null);
        if ($commodity === '') {
            continue;
        }
        // The hold can list the same commodity more than once; the upsert adds
        // the stacks together rather than keeping only the last.
        $stmt->execute([
            'cid' => $id,
            'c' => mb_substr($commodity, 0, 64),
            'stolen' => (int) (bool) ($item['stolen'] ?? false),
            'loc' => $item['locName'] ?? null,
            'qty' => (int) ($item['qty'] ?? 0),
            'value' => (int) ($item['value'] ?? 0),
        ]);
    }

    fc_update_carrier($id, ['cargo_at' => $ts]);
}

function fc_capi_apply_market(int $id, array $data, string $ts): void
{
    $commodities = $data['market']['commodities'] ?? null;
    if (!is_array($commodities)) {
        return;
    }

    fc_exec('DELETE FROM fc_market WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_market (carrier_id, commodity, loc_name, category, stock, demand, buy_price, sell_price, mean_price)
         VALUES (:cid, :c, :loc, :cat, :stock, :demand, :buy, :sell, :mean)
         ON DUPLICATE KEY UPDATE stock = VALUES(stock), demand = VALUES(demand)'
    );

    foreach ($commodities as $item) {
        $category = (string) ($item['categoryname'] ?? '');
        if (strcasecmp($category, 'NonMarketable') === 0) {
            continue;
        }
        $commodity = fc_clean_symbol($item['name'] ?? null);
        if ($commodity === '') {
            continue;
        }
        if (!fc_is_traded((int) ($item['stock'] ?? 0), (int) ($item['demand'] ?? 0))) {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'c' => mb_substr($commodity, 0, 64),
            'loc' => $item['locName'] ?? null,
            'cat' => $category !== '' ? $category : null,
            'stock' => (int) ($item['stock'] ?? 0),
            'demand' => (int) ($item['demand'] ?? 0),
            'buy' => (int) ($item['buyPrice'] ?? 0),
            'sell' => (int) ($item['sellPrice'] ?? 0),
            'mean' => (int) ($item['meanPrice'] ?? 0),
        ]);
    }

    fc_update_carrier($id, ['market_at' => $ts, 'market_id' => $id]);
}

/**
 * The live buy and sell order book.
 *
 * This is the only authority on which orders are still standing. The journal
 * emits CarrierTradeOrder when an order is placed and again when it is
 * cancelled, but nothing at all when one is simply filled — so an order table
 * built from the journal alone only ever grows, and ends up listing trades
 * that completed months ago.
 *
 * Frontier is inconsistent about the container: purchases come back as a list,
 * sales as an object keyed by commodity id. Both are handled, and an
 * unrecognised shape is logged rather than silently dropped.
 */
function fc_capi_apply_orders(int $id, array $data, string $ts): void
{
    // No `orders` key means this payload says nothing about orders. Only an
    // explicitly present (and possibly empty) book is allowed to clear rows.
    if (!isset($data['orders']) || !is_array($data['orders'])) {
        return;
    }
    $commodities = $data['orders']['commodities'] ?? null;
    if (!is_array($commodities)) {
        return;
    }

    fc_exec('DELETE FROM fc_orders WHERE carrier_id = :id', ['id' => $id]);

    $stmt = fc_db()->prepare(
        'INSERT INTO fc_orders (carrier_id, commodity, black_market, loc_name, kind, amount, price, updated_at)
         VALUES (:cid, :c, :bm, :loc, :kind, :amount, :price, :ts)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount), price = VALUES(price),
                                 updated_at = VALUES(updated_at)'
    );

    foreach (['purchases' => 'buy', 'sales' => 'sell'] as $section => $kind) {
        $entries = $commodities[$section] ?? [];
        if (!is_array($entries)) {
            error_log("fc: unexpected shape for orders.commodities.{$section}: " . gettype($entries));
            continue;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $commodity = fc_clean_symbol($entry['name'] ?? null);
            if ($commodity === '') {
                continue;
            }
            $amount = fc_first_number($entry, ['purchaseOrder', 'stock', 'total', 'quantity', 'amount']);
            $price = fc_first_number($entry, ['price', 'unitPrice']);
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $stmt->execute([
                'cid' => $id,
                'c' => mb_substr($commodity, 0, 64),
                'bm' => (int) (bool) ($entry['blackmarket'] ?? $entry['blackMarket'] ?? false),
                'loc' => $entry['locName'] ?? null,
                'kind' => $kind,
                'amount' => $amount,
                'price' => $price ?? 0,
                'ts' => $ts,
            ]);
        }
    }

    fc_update_carrier($id, ['orders_at' => $ts]);
}

/** First of these keys holding a number, or null. */
function fc_first_number(array $source, array $keys): ?int
{
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_numeric($source[$key])) {
            return (int) $source[$key];
        }
    }
    return null;
}

function fc_capi_apply_shipyard(int $id, array $data, string $ts): void
{
    $list = $data['ships']['shipyard_list'] ?? null;
    // An empty shipyard comes back as an empty list, which is a fact worth
    // recording; a missing key means the service is not installed.
    if (!is_array($list)) {
        return;
    }

    fc_exec('DELETE FROM fc_shipyard WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_shipyard (carrier_id, ship, loc_name, base_value, stock)
         VALUES (:cid, :ship, :loc, :value, :stock)
         ON DUPLICATE KEY UPDATE base_value = VALUES(base_value), stock = VALUES(stock)'
    );

    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        $ship = fc_clean_symbol($item['name'] ?? null);
        if ($ship === '') {
            continue;
        }
        $stmt->execute([
            'cid' => $id,
            'ship' => mb_substr($ship, 0, 64),
            'loc' => ucfirst($ship),
            'value' => (int) ($item['basevalue'] ?? 0),
            'stock' => (int) ($item['stock'] ?? 1),
        ]);
    }

    fc_update_carrier($id, ['shipyard_at' => $ts]);
}

function fc_capi_apply_outfitting(int $id, array $data, string $ts): void
{
    $modules = $data['modules'] ?? null;
    if (!is_array($modules)) {
        return;
    }

    fc_exec('DELETE FROM fc_outfitting WHERE carrier_id = :id', ['id' => $id]);
    $stmt = fc_db()->prepare(
        'INSERT INTO fc_outfitting (carrier_id, module, loc_name, category, cost, stock)
         VALUES (:cid, :module, :loc, :cat, :cost, :stock)
         ON DUPLICATE KEY UPDATE cost = VALUES(cost), stock = VALUES(stock)'
    );

    foreach ($modules as $item) {
        if (!is_array($item)) {
            continue;
        }
        $module = fc_clean_symbol($item['name'] ?? null);
        $cost = (int) ($item['cost'] ?? 0);
        if ($module === '' || !fc_is_stocked_module($module, $cost)) {
            continue;
        }
        // Frontier's `category` here is the literal string "module" for every
        // row, which tells a reader nothing. The symbol prefix does better.
        $category = (string) ($item['category'] ?? '');
        if ($category === '' || strcasecmp($category, 'module') === 0) {
            $category = fc_module_category($module);
        }

        // A stock of -1 means the game does not track a count for this, not
        // that it owes you one.
        $stock = fc_first_number($item, ['stock']) ?? 1;

        $stmt->execute([
            'cid' => $id,
            'module' => mb_substr($module, 0, 96),
            'loc' => fc_module_label($module),
            'cat' => $category,
            'cost' => $cost,
            'stock' => $stock < 0 ? 0 : $stock,
        ]);
    }

    fc_update_carrier($id, ['outfitting_at' => $ts]);
}

/**
 * The completed itinerary, which carries real departure times.
 *
 * The journal only ever tells us about arrivals, so departures there are
 * inferred from the next one. These are the game's own figures, so they win.
 */
function fc_capi_apply_itinerary(int $id, array $data): void
{
    $completed = $data['itinerary']['completed'] ?? null;
    if (!is_array($completed)) {
        return;
    }

    foreach ($completed as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $arrival = fc_ts($stop['arrivalTime'] ?? null);
        $system = $stop['starsystem'] ?? null;
        if ($arrival === null || $system === null) {
            continue;
        }
        // Frontier timestamps an arrival a few seconds off from where the
        // journal put it, and names no body. Merging rather than inserting is
        // what stops every jump appearing twice, once with a body and once
        // without.
        fc_merge_arrival(
            $id,
            mb_substr((string) $system, 0, 128),
            null,
            $arrival,
            fc_ts($stop['departureTime'] ?? null),
        );
    }
}
