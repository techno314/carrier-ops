<?php

declare(strict_types=1);

/**
 * Fleet carrier service costs and the upkeep forecast.
 *
 * Frontier's Companion API hands out finance.coreCost / servicesCost directly,
 * but the player journal does not — it only tells us which crew are aboard and
 * whether each is enabled. So upkeep is reconstructed from the published cost
 * table instead. Figures are per week, from the in-game Carrier Administration
 * screen (Drake-Class Carrier, current as of Odyssey Update 11+).
 *
 * The key detail: a service that is installed but suspended still costs a
 * retainer. Uninstalling is the only way to zero it out. That is exactly what
 * the Activated/Enabled pair in CarrierStats encodes.
 *
 * A Javelin-Class charges the same way from the same table, with one exception
 * handled in fc_upkeep_estimate: its shipyard is part of the hull rather than
 * something bought, so it is a core service there and free. (Its Redemption
 * Office and Secure Warehouse cannot be installed at all, which needs no code
 * -- a service that cannot exist never appears in a roster.)
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Charged every week regardless, and covers the three core crews. */
const FC_CORE_UPKEEP = 5_000_000;

/** Wear-and-tear fee added per jump performed since the last upkeep tick. */
const FC_JUMP_FEE = 100_000;

/** Debt above this and the carrier is slated for decommissioning. */
const FC_DEBT_LIMIT = 250_000_000;

/**
 * Keyed by the CrewRole string the journal uses in CarrierStats.Crew[].
 *
 * `core` services are staffed out of the base fee and have no separate line.
 */
function fc_services(): array
{
    static $services = [
        'Captain' => [
            'label' => 'Bridge Crew', 'officer' => 'Deck Officer',
            'install' => 0, 'active' => 0, 'suspended' => 0, 'cargo' => 0, 'core' => true,
        ],
        'Commodities' => [
            'label' => 'Commodity Trading', 'officer' => 'Commodity Trader',
            'install' => 0, 'active' => 0, 'suspended' => 0, 'cargo' => 0, 'core' => true,
        ],
        'CarrierFuel' => [
            'label' => 'Tritium Depot', 'officer' => 'Tritium Dealer',
            'install' => 0, 'active' => 0, 'suspended' => 0, 'cargo' => 0, 'core' => true,
        ],
        'Refuel' => [
            'label' => 'Refuel', 'officer' => 'Fuel Supervisor',
            'install' => 40_000_000, 'active' => 1_500_000, 'suspended' => 750_000, 'cargo' => 500,
        ],
        'Repair' => [
            'label' => 'Repair', 'officer' => 'Head Mechanic',
            'install' => 50_000_000, 'active' => 1_500_000, 'suspended' => 750_000, 'cargo' => 180,
        ],
        'Rearm' => [
            'label' => 'Armoury', 'officer' => 'Chief Armourer',
            'install' => 95_000_000, 'active' => 1_500_000, 'suspended' => 750_000, 'cargo' => 250,
        ],
        'VoucherRedemption' => [
            'label' => 'Redemption Office', 'officer' => 'Office Liaison',
            'install' => 150_000_000, 'active' => 1_850_000, 'suspended' => 850_000, 'cargo' => 100,
        ],
        'Shipyard' => [
            'label' => 'Shipyard', 'officer' => 'Shipyard Manager',
            'install' => 250_000_000, 'active' => 6_500_000, 'suspended' => 1_800_000, 'cargo' => 3_000,
        ],
        'Outfitting' => [
            'label' => 'Outfitting', 'officer' => 'Outfitting Manager',
            'install' => 250_000_000, 'active' => 5_000_000, 'suspended' => 1_500_000, 'cargo' => 1_750,
        ],
        'BlackMarket' => [
            'label' => 'Secure Warehouse', 'officer' => 'Fence',
            'install' => 165_000_000, 'active' => 2_000_000, 'suspended' => 1_250_000, 'cargo' => 250,
        ],
        'Exploration' => [
            'label' => 'Universal Cartographics', 'officer' => 'Stellar Cartographer',
            'install' => 150_000_000, 'active' => 1_850_000, 'suspended' => 700_000, 'cargo' => 120,
        ],
        'Bartender' => [
            'label' => 'Concourse Bar', 'officer' => 'Bartender',
            'install' => 200_000_000, 'active' => 1_750_000, 'suspended' => 1_250_000, 'cargo' => 250,
        ],
        'VistaGenomics' => [
            'label' => 'Vista Genomics', 'officer' => 'Exobiologist',
            'install' => 150_000_000, 'active' => 1_500_000, 'suspended' => 700_000, 'cargo' => 120,
        ],
        'PioneerSupplies' => [
            'label' => 'Pioneer Supplies', 'officer' => 'Equipment Trader',
            'install' => 250_000_000, 'active' => 5_000_000, 'suspended' => 1_500_000, 'cargo' => 200,
        ],
    ];
    return $services;
}

function fc_service(string $role): ?array
{
    return fc_services()[$role] ?? null;
}

function fc_service_label(string $role): string
{
    return fc_service($role)['label'] ?? $role;
}

/**
 * Weekly upkeep from a crew roster, or from the game's own figures.
 *
 * If a Companion API payload has been through (see _capi.php) the carrier row
 * holds `core_cost` and `services_cost` as the game charges them, and those
 * replace the reconstruction entirely. The per-service breakdown is still
 * derived from the roster, since CAPI gives only the two totals.
 *
 * @param array $crew rows with crew_role / activated / enabled
 * @param ?array $carrier the carrier row, when the caller has one
 * @return array{core:int,services:int,total:int,lines:array,exact:bool}
 */
function fc_upkeep(array $crew, ?array $carrier = null): array
{
    // Tested inline rather than through fc_is_squadron_carrier: this file is
    // required by core.php, so it should not reach back up into it.
    $squadron = ($carrier['squadron_id'] ?? null) !== null;
    $upkeep = fc_upkeep_estimate($crew, $squadron);

    $core = $carrier['core_cost'] ?? null;
    $services = $carrier['services_cost'] ?? null;
    if ($core !== null && $services !== null) {
        $upkeep['core'] = (int) $core;
        $upkeep['services'] = (int) $services;
        $upkeep['total'] = (int) $core + (int) $services;
        $upkeep['exact'] = true;
    }

    return $upkeep;
}

/**
 * @param array $crew rows with crew_role / activated / enabled
 * @param bool $squadron true for a Javelin-Class, whose shipyard is free
 * @return array{core:int,services:int,total:int,lines:array,exact:bool}
 */
function fc_upkeep_estimate(array $crew, bool $squadron = false): array
{
    $services = 0;
    $lines = [];

    foreach ($crew as $member) {
        $role = (string) $member['crew_role'];
        $spec = fc_service($role);
        if ($spec === null || ($spec['core'] ?? false)) {
            continue;
        }
        // A Javelin's shipyard comes with the hull -- always installed, no
        // hiring price, no salary, and Frontier bills nothing for it. Charging
        // a Drake's 6.5M for it would contradict the servicesCost of 0 that the
        // same card shows as the exact figure.
        if ($squadron && $role === 'Shipyard') {
            continue;
        }
        // Not activated means the service was never installed (or was sold),
        // so it costs nothing at all.
        if ((int) $member['activated'] !== 1) {
            continue;
        }
        $running = (int) $member['enabled'] === 1;
        $cost = $running ? $spec['active'] : $spec['suspended'];
        $services += $cost;
        $lines[] = [
            'role' => $role,
            'label' => $spec['label'],
            'officer' => $spec['officer'],
            'name' => $member['crew_name'] ?? null,
            'running' => $running,
            'cost' => $cost,
            'cargo' => $spec['cargo'],
        ];
    }

    usort($lines, static fn(array $a, array $b) => $b['cost'] <=> $a['cost']);

    return [
        'core' => FC_CORE_UPKEEP,
        'services' => $services,
        'total' => FC_CORE_UPKEEP + $services,
        'lines' => $lines,
        'exact' => false,
    ];
}

/**
 * Upkeep is taken during the weekly server tick: 07:00 UTC every Thursday.
 *
 * @return int unix timestamp of the next tick
 */
function fc_next_upkeep_tick(?int $now = null): int
{
    $now ??= time();
    // strtotime's "next thursday" rolls to the following week when today *is*
    // Thursday, which would skip this morning's tick. Walk it by hand instead.
    $today = (int) gmdate('N', $now);             // 1 = Monday .. 7 = Sunday
    $daysAhead = (4 - $today + 7) % 7;            // 4 = Thursday
    $candidate = strtotime(gmdate('Y-m-d', $now + $daysAhead * 86400) . ' 07:00:00 UTC');
    if ($candidate !== false && $candidate <= $now) {
        $candidate += 7 * 86400;
    }
    return $candidate === false ? $now : $candidate;
}

/** The tick before this one — the window jump fees accumulate in. */
function fc_last_upkeep_tick(?int $now = null): int
{
    return fc_next_upkeep_tick($now) - 7 * 86400;
}

/**
 * How long the balance lasts at the current burn rate.
 *
 * @return array{weekly:int,weeks:?int,broke_at:?int,jump_fees:int}
 */
function fc_solvency(array $upkeep, ?int $balance, int $jumpsThisWeek = 0, ?int $now = null): array
{
    $now ??= time();
    $jumpFees = $jumpsThisWeek * FC_JUMP_FEE;
    $weekly = $upkeep['total'];

    if ($balance === null || $weekly <= 0) {
        return ['weekly' => $weekly, 'weeks' => null, 'broke_at' => null, 'jump_fees' => $jumpFees];
    }

    // The imminent tick also collects this week's jump fees; later weeks are
    // forecast at the standing rate only, since future jumps are unknowable.
    $remaining = $balance - $jumpFees;
    if ($remaining < $weekly) {
        return [
            'weekly' => $weekly,
            'weeks' => 0,
            'broke_at' => fc_next_upkeep_tick($now),
            'jump_fees' => $jumpFees,
        ];
    }

    $weeks = (int) floor($remaining / $weekly);
    return [
        'weekly' => $weekly,
        'weeks' => $weeks,
        'broke_at' => fc_next_upkeep_tick($now) + ($weeks - 1) * 7 * 86400,
        'jump_fees' => $jumpFees,
    ];
}
