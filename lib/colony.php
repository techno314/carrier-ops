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

/**
 * How often a planner should do things, decided here rather than in each copy.
 *
 * These used to be constants compiled into the client, which meant changing one
 * required everybody on the build to download a new planner -- and a crew is
 * exactly the population that will not. So the board says, and the planners
 * follow: it is the one machine all of them already talk to.
 *
 * Bounded on the way in and on the way out. A setting that arrives as nonsense
 * must not be able to make every client hammer the board or stop reporting.
 *
 * @return array<string,int>
 */
function fc_colony_settings(): array
{
    static $bounds = [
        // Two seconds keeps a crew standing at the same market in step; below
        // that the reports cost more than the freshness is worth.
        'reportSeconds' => [2, 1, 300],
        // The journal is on local disk and cheap, but a rescan is still tens of
        // milliseconds and there is nothing to see between game writes.
        'journalSeconds' => [2, 1, 60],
        // Frontier's own cache is 10-15 minutes wide, so asking faster than
        // this returns the same bytes and spends somebody else's rate limit.
        'carrierSeconds' => [120, 30, 3600],
        // How long after a report somebody still counts as hauling. Several
        // report intervals, so one slow request does not blink them out.
        'presentSeconds' => [60, 10, 3600],
    ];

    $stored = [];
    foreach (fc_all("SELECT k, v FROM fc_meta WHERE k LIKE 'colony_%'") as $row) {
        $stored[$row['k']] = $row['v'];
    }

    $out = [];
    foreach ($bounds as $name => [$default, $min, $max]) {
        $value = $stored['colony_' . $name] ?? null;
        $value = is_numeric($value) ? (int) $value : $default;
        $out[$name] = max($min, min($max, $value));
    }
    return $out;
}

/** @return array{ok:bool,note:?string} */
function fc_colony_set_settings(array $values): array
{
    $allowed = array_keys(fc_colony_settings());
    $written = 0;
    foreach ($values as $name => $value) {
        if (!in_array($name, $allowed, true) || !is_numeric($value)) {
            continue;
        }
        fc_exec(
            "INSERT INTO fc_meta (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            ['k' => 'colony_' . $name, 'v' => (string) (int) $value],
        );
        $written++;
    }
    return ['ok' => $written > 0, 'note' => $written === 0 ? 'Nothing recognised to save.' : null];
}

/**
 * Everything about one system: its builds, its crew, its invitations.
 *
 * A system is the unit people mean when they say "the build" -- it is what a
 * token grants, and what the admin panel manages.
 */
function fc_colony_room(string $system): array
{
    $sites = fc_colony_sites_in($system);
    $ids = array_column($sites, 'market_id');

    $haulers = $ids === [] ? [] : fc_all(
        'SELECT h.*, s.name AS site_name
           FROM fc_colony_haulers h
           JOIN fc_colony_sites s ON s.market_id = h.market_id
          WHERE s.system = :sys
          ORDER BY h.updated_at DESC',
        ['sys' => $system],
    );

    $tokens = fc_all(
        'SELECT * FROM fc_colony_tokens WHERE system = :sys ORDER BY id DESC',
        ['sys' => $system],
    );

    return ['system' => $system, 'sites' => $sites, 'haulers' => $haulers, 'tokens' => $tokens];
}

/** Every system anybody is building in. @return string[] */
function fc_colony_rooms(): array
{
    return array_column(
        fc_all('SELECT system FROM fc_colony_sites
                 WHERE system IS NOT NULL AND system <> ""
                 GROUP BY system ORDER BY MAX(read_at) DESC'),
        'system',
    );
}

/**
 * Erase a system's build entirely: sites, manifests, crew, and invitations.
 *
 * Unlike a carrier, most of this comes back on its own -- every planner still
 * running reports its whole position within a couple of seconds, and the
 * manifest returns when somebody docks. What does not come back is the tokens,
 * which is the point of offering this at all.
 *
 * @return array<string,int>
 */
function fc_colony_delete_room(string $system): array
{
    $ids = array_column(fc_colony_sites_in($system), 'market_id');
    $removed = ['tokens' => fc_exec('DELETE FROM fc_colony_tokens WHERE system = :sys', ['sys' => $system])];

    foreach (['fc_colony_stock', 'fc_colony_haulers', 'fc_colony_needs'] as $table) {
        $removed[$table] = 0;
        foreach ($ids as $id) {
            $removed[$table] += fc_exec("DELETE FROM {$table} WHERE market_id = :m", ['m' => $id]);
        }
    }
    $removed['sites'] = fc_exec('DELETE FROM fc_colony_sites WHERE system = :sys', ['sys' => $system]);
    return $removed;
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
        // Wrapped for the same reason as the stock below, and it matters more
        // here: this delete empties the manifest for every hauler at once, not
        // just one person's share of it.
        $db = fc_db();
        $ownsManifest = !$db->inTransaction();
        if ($ownsManifest) {
            $db->beginTransaction();
        }

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

        // The site row is committed with its manifest, so progress and the
        // requirements it describes can never disagree in what a reader sees.
        if ($ownsManifest) {
            $db->commit();
        }
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

    /*
     * Replaced wholesale rather than merged. The planner sends its entire
     * position each time, so a commodity missing from the report is one the
     * reporter no longer has -- merging would leave it there for ever.
     *
     * In a transaction, because "replace" is a delete and then some inserts,
     * and without one those commit separately. Everybody else on the build is
     * reading this table every couple of seconds, and in the gap between the
     * two this hauler holds nothing at all -- which is precisely what was seen:
     * `Others have` emptying for about half a second and coming back.
     *
     * It is a small window and it does not matter how often it is hit, only
     * that a reader can land in it. Four people reporting every two seconds
     * land in it regularly.
     */
    $db = fc_db();
    $owned = !$db->inTransaction();
    if ($owned) {
        $db->beginTransaction();
    }

    try {
        fc_exec(
            'DELETE FROM fc_colony_stock WHERE market_id = :m AND hauler = :u',
            ['m' => $marketId, 'u' => $hauler],
        );
        if ($stock !== []) {
            $stmt = $db->prepare(
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
        if ($owned) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($owned) {
            $db->rollBack();
        }
        throw $e;
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
        // Sent with the view rather than fetched separately: a planner asks for
        // this every couple of seconds anyway, so a change made in the admin
        // panel reaches every crew member on their next report.
        'settings' => fc_colony_settings(),
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
