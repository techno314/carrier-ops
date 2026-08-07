<?php

declare(strict_types=1);

/**
 * Colonisation builds that several people haul to at once.
 *
 * A commander's journal is a private account of one person's evening. It knows
 * what the construction depot said when they last docked and what is in their
 * own hold, and nothing whatever about the four other people flying to the same
 * site. So two of them buy fifty thousand tonnes of steel for a site that
 * wanted seventy, and neither finds out until the second one arrives.
 *
 * This is where those separate views are added together. Each planner reports
 * what it can see -- the manifest if its commander has docked recently, its own
 * ship and carrier, what it has delivered -- and reads back the sum.
 *
 * Keyed on the site's MarketID, which the game gives every construction depot
 * and which is the same number in everybody's journal. The name is carried
 * alongside because that is what people actually say to each other, and it is
 * how a build is looked up.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/**
 * Build tokens are prefixed so they can be told apart on sight.
 *
 * The colony routes accept an account key or one of these in the same header,
 * and the difference decides what the caller may touch. Without a marker that
 * would mean a database lookup to find out which kind of thing arrived.
 */
const FC_COLONY_TOKEN_PREFIX = 'fcb_';

/**
 * Who is calling, and what they are allowed to see.
 *
 * Two kinds of caller. An account key belongs to somebody with a login here and
 * works on any build. A build token belongs to one construction site and works
 * on nothing else -- which is the case worth designing for, because the people
 * hauling to a colony are a scratch crew assembled for a fortnight and will not
 * register an account to move some steel.
 *
 * @return array{hauler:string,system:?string,user:?array}|null
 */
function fc_colony_caller(string $key, ?array $user): ?array
{
    $key = trim($key);

    if (str_starts_with($key, FC_COLONY_TOKEN_PREFIX)) {
        $row = fc_one(
            'SELECT * FROM fc_colony_tokens WHERE token_hash = :h AND revoked_at IS NULL',
            ['h' => hash('sha256', $key)],
        );
        if ($row === null) {
            return null;
        }
        fc_exec('UPDATE fc_colony_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id',
                ['id' => $row['id']]);
        return [
            'hauler' => 't' . $row['id'],
            'system' => (string) $row['system'],
            'user' => null,
        ];
    }

    if ($user !== null) {
        return ['hauler' => 'u' . $user['id'], 'system' => null, 'user' => $user];
    }
    return null;
}

/**
 * Mint an invitation to a colony.
 *
 * Returned once and never again: only its hash is kept, so a lost token is
 * replaced rather than looked up.
 *
 * @return array{token:string,id:int}
 */
function fc_colony_mint_token(string $system, ?int $marketId, string $hauler, ?string $label): array
{
    $token = FC_COLONY_TOKEN_PREFIX . bin2hex(random_bytes(18));
    fc_exec(
        'INSERT INTO fc_colony_tokens (system, market_id, token_hash, label, created_by, created_at)
         VALUES (:sys, :m, :h, :l, :c, UTC_TIMESTAMP())',
        [
            'sys' => mb_substr($system, 0, 128),
            'm' => $marketId,
            'h' => hash('sha256', $token),
            'l' => $label === null || trim($label) === '' ? null : mb_substr(trim($label), 0, 64),
            'c' => $hauler,
        ],
    );
    return ['token' => $token, 'id' => (int) fc_db()->lastInsertId()];
}

/**
 * Is this caller already hauling somewhere in this system?
 *
 * Asked of the system rather than of one site, to match what a token grants.
 * Somebody who has done a run to the colonisation ship has as much standing to
 * invite people to the orbital site next door as to the one they flew to.
 */
function fc_colony_participates(string $system, string $hauler): bool
{
    return fc_one(
        'SELECT 1 AS y
           FROM fc_colony_haulers h
           JOIN fc_colony_sites s ON s.market_id = h.market_id
          WHERE s.system = :sys AND h.hauler = :h
          LIMIT 1',
        ['sys' => $system, 'h' => $hauler],
    ) !== null;
}

/** Every build in a system, most recently read first. @return array<int,array> */
function fc_colony_sites_in(string $system): array
{
    return fc_all(
        'SELECT * FROM fc_colony_sites WHERE system = :sys ORDER BY read_at DESC',
        ['sys' => $system],
    );
}

/** A build by its MarketID. */
function fc_colony_site(int $marketId): ?array
{
    return fc_one('SELECT * FROM fc_colony_sites WHERE market_id = :id', ['id' => $marketId]);
}

/**
 * Builds whose name matches, most recently read first.
 *
 * Matched loosely on purpose. The game calls these
 * "$EXT_PANEL_ColonisationShip; Cunha Gateway" and
 * "Orbital Construction Site: Brongniart Vision"; people call them
 * "Cunha Gateway". Whatever is stored, someone typing the part they say out
 * loud should find it.
 *
 * @return array<int,array>
 */
function fc_colony_search(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    if (ctype_digit($query)) {
        $site = fc_colony_site((int) $query);
        return $site === null ? [] : [$site];
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
    return fc_all(
        'SELECT * FROM fc_colony_sites
          WHERE name LIKE :a OR system LIKE :b
          ORDER BY read_at DESC LIMIT 25',
        ['a' => $like, 'b' => $like],
    );
}

/**
 * Take one planner's report.
 *
 * Every part is optional except the site it is about. A hauler who has not
 * docked at the depot for a week still has a hold worth pooling, and somebody
 * who has just docked but owns no carrier still has the freshest manifest.
 *
 * @return array{ok:bool,market_id:?int,note:?string,needs:int,stock:int}
 */
function fc_colony_apply_report(string $hauler, array $data): array
{
    $blank = ['ok' => false, 'market_id' => null, 'note' => null, 'needs' => 0, 'stock' => 0];

    $marketId = isset($data['marketId']) && is_numeric($data['marketId']) ? (int) $data['marketId'] : 0;
    if ($marketId <= 0) {
        return $blank + ['note' => 'The report named no construction site.'];
    }

    $readAt = fc_ts($data['readAt'] ?? null);
    $name = trim((string) ($data['name'] ?? ''));
    $existing = fc_colony_site($marketId);

    // A site row is created by whoever reports it first, even if all they have
    // is an id and a name. The manifest can arrive later, from anyone.
    if ($existing === null) {
        fc_exec(
            'INSERT INTO fc_colony_sites (market_id, name, system, progress, read_at, read_by, created_at)
             VALUES (:id, :n, :s, :p, :r, :u, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name)',
            [
                'id' => $marketId,
                'n' => mb_substr($name === '' ? 'Site ' . $marketId : $name, 0, 128),
                's' => $data['system'] ?? null,
                'p' => (float) ($data['progress'] ?? 0),
                'r' => $readAt ?? gmdate('Y-m-d H:i:s'),
                'u' => $hauler,
            ],
        );
        $existing = fc_colony_site($marketId);
    }
    if ($existing === null) {
        return $blank;
    }

    $needs = is_array($data['needs'] ?? null) ? $data['needs'] : [];
    $applied = 0;

    // The manifest is a whole snapshot, so a newer one replaces it outright and
    // an older one is refused. Readings arrive from several people in whatever
    // order their machines happen to send them, and "most recent wins" is the
    // only rule that survives that.
    if ($needs !== [] && $readAt !== null && strcmp($readAt, (string) $existing['read_at']) >= 0) {
        fc_exec('DELETE FROM fc_colony_needs WHERE market_id = :id', ['id' => $marketId]);
        $stmt = fc_db()->prepare(
            'INSERT INTO fc_colony_needs (market_id, commodity, loc_name, required, provided, payment)
             VALUES (:id, :c, :l, :r, :p, :pay)
             ON DUPLICATE KEY UPDATE required = VALUES(required), provided = VALUES(provided)'
        );
        foreach ($needs as $need) {
            if (!is_array($need)) {
                continue;
            }
            $commodity = fc_clean_symbol($need['commodity'] ?? null);
            if ($commodity === '') {
                continue;
            }
            $stmt->execute([
                'id' => $marketId,
                'c' => mb_substr($commodity, 0, 64),
                'l' => isset($need['name']) ? mb_substr((string) $need['name'], 0, 96) : null,
                'r' => (int) ($need['required'] ?? 0),
                'p' => (int) ($need['provided'] ?? 0),
                'pay' => (int) ($need['payment'] ?? 0),
            ]);
            $applied++;
        }

        fc_exec(
            'UPDATE fc_colony_sites
                SET name = :n, system = COALESCE(:s, system), progress = :p,
                    complete = :c, failed = :f, read_at = :r, read_by = :u
              WHERE market_id = :id',
            [
                'n' => mb_substr($name === '' ? (string) $existing['name'] : $name, 0, 128),
                's' => $data['system'] ?? null,
                'p' => (float) ($data['progress'] ?? 0),
                'c' => (int) (bool) ($data['complete'] ?? false),
                'f' => (int) (bool) ($data['failed'] ?? false),
                'r' => $readAt,
                'u' => $hauler,
                'id' => $marketId,
            ],
        );
    }

    // Who is hauling, and in what. Always applied: this is the reporter's own
    // position and nobody else can contradict it.
    fc_exec(
        'INSERT INTO fc_colony_haulers (market_id, hauler, cmdr, carrier, ship, cargo_capacity, updated_at)
         VALUES (:m, :u, :c, :car, :s, :cap, UTC_TIMESTAMP())
         -- COALESCE, not VALUES: a report that says nothing about the ship is
         -- not a report that the ship is gone. Only the stock list is a whole
         -- statement of position; everything here is filled in as it is learned,
         -- and a planner that has not seen a Loadout yet would otherwise erase
         -- what an earlier one already knew.
         ON DUPLICATE KEY UPDATE cmdr = COALESCE(VALUES(cmdr), cmdr),
                                 carrier = COALESCE(VALUES(carrier), carrier),
                                 ship = COALESCE(VALUES(ship), ship),
                                 cargo_capacity = COALESCE(VALUES(cargo_capacity), cargo_capacity),
                                 updated_at = VALUES(updated_at)',
        [
            'm' => $marketId,
            'u' => $hauler,
            'c' => isset($data['cmdr']) ? mb_substr((string) $data['cmdr'], 0, 64) : null,
            'car' => isset($data['carrier']) ? mb_substr((string) $data['carrier'], 0, 16) : null,
            's' => isset($data['ship']) ? mb_substr((string) $data['ship'], 0, 64) : null,
            'cap' => isset($data['cargoCapacity']) ? (int) $data['cargoCapacity'] : null,
        ],
    );

    $stock = is_array($data['stock'] ?? null) ? $data['stock'] : [];
    $stocked = 0;

    // Replaced wholesale rather than merged. The planner sends its entire
    // position each time, so a commodity missing from the report is one the
    // reporter no longer has -- merging would leave it there for ever.
    fc_exec(
        'DELETE FROM fc_colony_stock WHERE market_id = :m AND hauler = :u',
        ['m' => $marketId, 'u' => $hauler],
    );
    if ($stock !== []) {
        $stmt = fc_db()->prepare(
            'INSERT INTO fc_colony_stock (market_id, hauler, commodity, in_ship, on_carrier, delivered)
             VALUES (:m, :u, :c, :s, :car, :d)
             ON DUPLICATE KEY UPDATE in_ship = VALUES(in_ship),
                                     on_carrier = VALUES(on_carrier),
                                     delivered = VALUES(delivered)'
        );
        foreach ($stock as $row) {
            if (!is_array($row)) {
                continue;
            }
            $commodity = fc_clean_symbol($row['commodity'] ?? null);
            $ship = (int) ($row['ship'] ?? 0);
            $carrier = (int) ($row['carrier'] ?? 0);
            $delivered = (int) ($row['delivered'] ?? 0);
            if ($commodity === '' || ($ship <= 0 && $carrier <= 0 && $delivered <= 0)) {
                continue;
            }
            $stmt->execute([
                'm' => $marketId, 'u' => $hauler, 'c' => mb_substr($commodity, 0, 64),
                's' => max(0, $ship), 'car' => max(0, $carrier), 'd' => max(0, $delivered),
            ]);
            $stocked++;
        }
    }

    return [
        'ok' => true,
        'market_id' => $marketId,
        'note' => $applied === 0 && $needs !== []
            ? 'Somebody has read this site more recently, so your manifest was not applied.'
            : null,
        'needs' => $applied,
        'stock' => $stocked,
    ];
}

/**
 * The pooled view of a build.
 *
 * Per commodity: what the site wants, what it has been given, and what the
 * group is holding between them -- split by person, because "we have 40,000 t
 * of steel" is only useful if you can also tell whose ship it is in and whether
 * they are anywhere near the site.
 */
function fc_colony_view(array $site): array
{
    $marketId = (int) $site['market_id'];

    $needs = fc_all(
        'SELECT * FROM fc_colony_needs WHERE market_id = :id ORDER BY required DESC, commodity',
        ['id' => $marketId],
    );

    $haulers = fc_all(
        'SELECT * FROM fc_colony_haulers WHERE market_id = :id ORDER BY updated_at DESC',
        ['id' => $marketId],
    );

    $stock = fc_all('SELECT * FROM fc_colony_stock WHERE market_id = :id', ['id' => $marketId]);

    // Indexed by commodity so the per-person split can be attached to the row
    // it belongs to rather than shipped as a second list to be joined by hand.
    $byCommodity = [];
    foreach ($stock as $row) {
        $byCommodity[$row['commodity']][] = $row;
    }

    // A token holder has no account name to fall back on, so an unnamed hauler
    // is described by what they are rather than left blank.
    $names = [];
    foreach ($haulers as $hauler) {
        $names[$hauler['hauler']] = $hauler['cmdr'] ?: 'a hauler';
    }

    $out = [];
    foreach ($needs as $need) {
        $rows = $byCommodity[$need['commodity']] ?? [];
        $ship = $carrier = $delivered = 0;
        $who = [];
        foreach ($rows as $row) {
            $ship += (int) $row['in_ship'];
            $carrier += (int) $row['on_carrier'];
            $delivered += (int) $row['delivered'];
            $who[] = [
                'cmdr' => $names[$row['hauler']] ?? 'a hauler',
                'ship' => (int) $row['in_ship'],
                'carrier' => (int) $row['on_carrier'],
                'delivered' => (int) $row['delivered'],
            ];
        }

        $required = (int) $need['required'];
        $provided = (int) $need['provided'];
        $short = max(0, $required - $provided);

        $out[] = [
            'commodity' => $need['commodity'],
            'name' => $need['loc_name'],
            'required' => $required,
            'provided' => $provided,
            'remaining' => $short,
            // What the group already holds against what is still wanted, so the
            // planner can say "stop buying steel" without doing the sum again.
            'held' => ['ship' => $ship, 'carrier' => $carrier],
            'toBuy' => max(0, $short - $ship - $carrier),
            'haulers' => $who,
        ];
    }

    return [
        'site' => [
            'marketId' => (string) $marketId,
            'name' => $site['name'],
            'system' => $site['system'],
            'progress' => (float) $site['progress'],
            'complete' => (bool) $site['complete'],
            'failed' => (bool) $site['failed'],
            'readAt' => $site['read_at'],
        ],
        'haulers' => array_map(static fn(array $h) => [
            'cmdr' => $h['cmdr'] ?: 'a hauler',
            'carrier' => $h['carrier'],
            'ship' => $h['ship'],
            'cargoCapacity' => $h['cargo_capacity'] === null ? null : (int) $h['cargo_capacity'],
            'updatedAt' => $h['updated_at'],
        ], $haulers),
        'needs' => $out,
    ];
}
