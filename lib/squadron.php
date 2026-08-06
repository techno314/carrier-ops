<?php

declare(strict_types=1);

/**
 * Squadron carriers (Javelin-Class).
 *
 * A Javelin belongs to a squadron rather than to a commander, and that one
 * difference is what everything here exists to handle. A personal carrier is
 * proved yours by a Companion API payload fetched with your own token; a
 * squadron's cannot be, because it is not yours -- it is the squadron's, and
 * you happen to be in it.
 *
 * Three things follow.
 *
 * Identity. `/squadron` reports the carrier under `squadronCarrier` with the
 * same shape as `/fleetcarrier` minus market, ships and modules -- but with
 * `carrierId` null and no `market.id`, so the payload alone cannot say which
 * carrier it is. The number comes from the journal instead: the game writes
 * `CarrierLocation` for a squadron carrier you have access to, exactly as it
 * does for your own, and that event carries the real CarrierID. See
 * fc_squadron_bind for how the two are joined up.
 *
 * Ownership. `/squadron` gives `ownerId`, which is a commander id -- it matches
 * `commander.id` from `/profile`, not the customer_id from `/me`. That is an
 * exact answer to who leads the squadron, so it is what ownership is read from.
 * Rank is deliberately not used for this: a leader may rename ranks freely (the
 * Fuel Rats' rank 0 is called `rat`), so "rank 0 is the leader" is a guess,
 * whereas ownerId is a fact. Rank drives delegated access only, which is the
 * job it can actually do.
 *
 * Visibility. A squadron carrier is its squadron's shared asset, so every
 * member sees it by default, and the owner may publish it -- including its
 * finances, which a personal carrier never exposes.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// capi.php is deliberately not required here. Everything below runs only while
// talking to Frontier, and every route that does -- capi.php, settings.php,
// bin/cron.php -- reaches this through capi_auth.php, which pulls capi.php in
// via ingest.php. Requiring it would put 700 lines of parser in front of every
// page request instead, on a host with five workers.
//
// Who may see a squadron carrier is a different question and lives in core.php
// beside fc_owns, so that access control stays in one place and pages that only
// render a carrier never load any of this.

/**
 * Record what a Frontier link's squadron is, and apply the squadron's carrier.
 *
 * Two calls, because neither is sufficient alone: `/profile` says which
 * commander this token belongs to and `/squadron` says what the squadron is and
 * who leads it. Matching one to the other is what turns "a member" into "this
 * member, at this rank".
 *
 * `callsign` is set whenever the squadron turned out to have a carrier, bound
 * or not, so the caller can record the fetch in the upload log either way.
 *
 * @return array{ok:bool,note:?string,error:?string,carrier_id:?int,callsign:?string}
 */
function fc_squadron_sync(array $user, array $link, string $token, ?string $ts = null): array
{
    $ts ??= gmdate('Y-m-d H:i:s');
    $linkId = (int) $link['id'];
    $blank = ['ok' => false, 'note' => null, 'error' => null, 'carrier_id' => null, 'callsign' => null];

    $profile = fc_capi_get('/profile', $token);
    if (!is_array($profile['data'] ?? null)) {
        return $blank + ['error' => 'Frontier did not return a commander profile.'];
    }
    $commander = is_array($profile['data']['commander'] ?? null) ? $profile['data']['commander'] : [];
    $cmdrId = isset($commander['id']) && is_numeric($commander['id']) ? (int) $commander['id'] : null;
    $cmdrName = isset($commander['name']) ? mb_substr((string) $commander['name'], 0, 64) : null;

    // No squadron on the profile means no squadron: drop any membership we had
    // recorded, so leaving one takes access away rather than leaving it behind.
    if (!is_array($profile['data']['squadron'] ?? null)) {
        fc_exec('DELETE FROM fc_squadron_members WHERE link_id = :l', ['l' => $linkId]);
        return $blank;
    }
    $profileSquadron = $profile['data']['squadron'];

    $response = fc_capi_get('/squadron', $token);
    if ($response['status'] === 204 || !is_array($response['data'] ?? null)) {
        fc_exec('DELETE FROM fc_squadron_members WHERE link_id = :l', ['l' => $linkId]);
        return $blank;
    }
    $squadron = $response['data'];

    $squadronId = isset($squadron['id']) && is_numeric($squadron['id']) ? (int) $squadron['id'] : null;
    if ($squadronId === null) {
        return $blank + ['error' => 'The squadron Frontier returned had no id.'];
    }

    fc_squadron_record_member($linkId, (int) $user['id'], $cmdrId, $cmdrName, $squadron, $profileSquadron, $ts);

    $sc = is_array($squadron['squadronCarrier'] ?? null) ? $squadron['squadronCarrier'] : null;
    if ($sc === null) {
        return ['ok' => true, 'note' => null, 'error' => null, 'carrier_id' => null, 'callsign' => null];
    }

    $carrierId = fc_squadron_bind($squadronId, $sc);
    $callsign = isset($sc['name']['callsign']) ? strtoupper((string) $sc['name']['callsign']) : null;

    // Remember an unbound carrier against the link, so the settings page can
    // offer to identify it rather than the fact being lost with this response.
    fc_exec(
        'UPDATE fc_squadron_members SET pending_carrier = :cs WHERE link_id = :l',
        ['cs' => $carrierId === null ? $callsign : null, 'l' => $linkId],
    );

    if ($carrierId === null) {
        return [
            'ok' => false,
            'error' => null,
            'carrier_id' => null,
            'callsign' => $callsign,
            'note' => 'Squadron ' . (string) ($squadron['tag'] ?? $squadronId) . ' has a carrier, but Frontier does'
                . ' not put an id on it, so which one it is has to be established another way — see your Frontier'
                . ' links in settings.',
        ];
    }

    fc_squadron_apply($carrierId, $squadron, $sc, $ts);

    return ['ok' => true, 'note' => null, 'error' => null, 'carrier_id' => $carrierId, 'callsign' => $callsign];
}

/**
 * Upsert one link's squadron membership.
 *
 * The rank comes from the member roster where possible, because that is a
 * number and survives renaming. `/profile` carries a rank name only -- either a
 * localisation token like `$Squadron_DefaultRankName_Rank0;` or whatever the
 * leader typed -- so it is kept for display and used for the number only when
 * the roster cannot be matched.
 */
function fc_squadron_record_member(
    int $linkId,
    int $userId,
    ?int $cmdrId,
    ?string $cmdrName,
    array $squadron,
    array $profileSquadron,
    string $ts,
): void {
    $rankName = isset($profileSquadron['rank']) ? (string) $profileSquadron['rank'] : null;
    $member = fc_squadron_find_member($squadron, $cmdrId, $cmdrName);

    $rankId = -1;
    if ($member !== null && isset($member['rank_id']) && is_numeric($member['rank_id'])) {
        $rankId = (int) $member['rank_id'];
    } elseif ($rankName !== null && preg_match('/Rank(\d+);?$/', $rankName, $m) === 1) {
        // Only the untouched default names encode their number. A renamed rank
        // does not, and guessing one would be worse than admitting we do not
        // know -- rank -1 grants nothing beyond plain membership.
        $rankId = (int) $m[1];
    }

    $joined = null;
    foreach ([$member['joined'] ?? null, $profileSquadron['joined'] ?? null] as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $joined = fc_squadron_time($candidate);
            if ($joined !== null) {
                break;
            }
        }
    }

    fc_exec(
        'INSERT INTO fc_squadron_members
            (link_id, user_id, cmdr_id, cmdr_name, squadron_id, squadron_name, squadron_tag,
             owner_cmdr_id, rank_id, rank_name, joined_at, synced_at)
         VALUES (:link, :user, :cmdr, :cname, :sq, :sname, :stag, :owner, :rank, :rname, :joined, :ts)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id), cmdr_id = VALUES(cmdr_id), cmdr_name = VALUES(cmdr_name),
            squadron_id = VALUES(squadron_id), squadron_name = VALUES(squadron_name),
            squadron_tag = VALUES(squadron_tag), owner_cmdr_id = VALUES(owner_cmdr_id),
            rank_id = VALUES(rank_id), rank_name = VALUES(rank_name),
            joined_at = VALUES(joined_at), synced_at = VALUES(synced_at)',
        [
            'link' => $linkId,
            'user' => $userId,
            'cmdr' => $cmdrId,
            'cname' => $cmdrName,
            'sq' => (int) $squadron['id'],
            'sname' => isset($squadron['name']) ? mb_substr((string) $squadron['name'], 0, 128) : null,
            'stag' => isset($squadron['tag']) ? mb_substr((string) $squadron['tag'], 0, 8) : null,
            'owner' => isset($squadron['ownerId']) && is_numeric($squadron['ownerId'])
                ? (int) $squadron['ownerId']
                : null,
            'rank' => $rankId,
            'rname' => $rankName === null ? null : mb_substr(fc_squadron_rank_label($rankName), 0, 64),
            'joined' => $joined,
            'ts' => $ts,
        ],
    );
}

/**
 * Find this commander's own row in the roster.
 *
 * Which field holds the commander id is not documented, so all three plausible
 * ones are tried before falling back to the name. A 300-member roster is walked
 * at most once per sync, which is every fifteen minutes at the fastest.
 */
function fc_squadron_find_member(array $squadron, ?int $cmdrId, ?string $cmdrName): ?array
{
    $members = $squadron['members'] ?? null;
    if (!is_array($members)) {
        return null;
    }

    foreach ($members as $member) {
        if (!is_array($member)) {
            continue;
        }
        if ($cmdrId !== null) {
            foreach (['user_id', 'member_id', 'commanderId'] as $key) {
                if (isset($member[$key]) && is_numeric($member[$key]) && (int) $member[$key] === $cmdrId) {
                    return $member;
                }
            }
        }
        $name = $member['name'] ?? null;
        if ($cmdrName !== null && is_string($name) && strcasecmp($name, $cmdrName) === 0) {
            return $member;
        }
    }

    return null;
}

/**
 * Turn a rank into something worth showing.
 *
 * Frontier sends the untouched defaults as localisation tokens and anything the
 * leader renamed as plain text, so only the former needs work.
 */
function fc_squadron_rank_label(string $rank): string
{
    if (preg_match('/^\$Squadron_\w*RankName_Rank(\d+);?$/', $rank, $m) === 1) {
        return 'Rank ' . $m[1];
    }
    return trim($rank);
}

/** Frontier's `YYYY-MM-DD HH:MM:SS`, or null if it is something else. */
function fc_squadron_time(string $value): ?string
{
    $ts = strtotime($value . ' UTC');
    return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Work out which carrier row the squadron's carrier is.
 *
 * Frontier gives the payload no id, so the number has to come from somewhere
 * else. In order of how much they are worth:
 *
 *   1. A row already bound to this squadron. Binding happens once and sticks.
 *   2. A row whose callsign is the squadron's tag. A Javelin's callsign is its
 *      squadron's tag -- PDKD's carrier is called PDKD -- so once any journal
 *      event names the carrier, this finds it exactly.
 *   3. Nothing. The board says so rather than guessing, and the owner can bind
 *      it by hand from the manage tab.
 *
 * A row is only taken if it is free to take: already this squadron's, or owned
 * by nobody. A carrier somebody else has claimed is never quietly reassigned.
 */
function fc_squadron_bind(int $squadronId, array $sc): ?int
{
    $bound = fc_one('SELECT id FROM fc_carriers WHERE squadron_id = :sq LIMIT 1', ['sq' => $squadronId]);
    if ($bound !== null) {
        return (int) $bound['id'];
    }

    $callsign = isset($sc['name']['callsign']) ? strtoupper((string) $sc['name']['callsign']) : null;
    if ($callsign === null || $callsign === '') {
        return null;
    }

    $row = fc_carrier_by_callsign($callsign);
    if ($row === null) {
        return null;
    }
    if ($row['owner_user_id'] !== null || ($row['squadron_id'] ?? null) !== null) {
        return null;   // somebody's personal carrier, or another squadron's
    }

    return (int) $row['id'];
}

/**
 * Attach a carrier row to a squadron, whatever route found it.
 *
 * Also used by the manual bind on the manage tab, which is why it is separate
 * from the sync: the two disagree about how the id was arrived at but not about
 * what to write once it has been.
 */
function fc_squadron_claim(int $carrierId, array $squadron): void
{
    $ownerCmdr = isset($squadron['ownerId']) && is_numeric($squadron['ownerId'])
        ? (int) $squadron['ownerId']
        : null;

    // The owner is whichever board account holds a link for that commander. It
    // is often nobody -- a squadron leader need not use this board -- and the
    // carrier is perfectly usable in that state, just without anyone able to
    // change its settings.
    $ownerUser = null;
    if ($ownerCmdr !== null) {
        $row = fc_one(
            'SELECT user_id FROM fc_squadron_members WHERE cmdr_id = :c AND squadron_id = :sq LIMIT 1',
            ['c' => $ownerCmdr, 'sq' => (int) $squadron['id']],
        );
        $ownerUser = $row === null ? null : (int) $row['user_id'];
    }

    $fields = [
        'squadron_id' => (int) $squadron['id'],
        'squadron_name' => isset($squadron['name']) ? mb_substr((string) $squadron['name'], 0, 128) : null,
        'squadron_tag' => isset($squadron['tag']) ? mb_substr((string) $squadron['tag'], 0, 8) : null,
        'owner_cmdr_id' => $ownerCmdr,
    ];
    // Never clear an existing owner: leadership may pass to someone with no
    // account here, and a carrier that silently became unmanageable would be a
    // worse outcome than one whose owner is briefly out of date.
    if ($ownerUser !== null) {
        $fields['owner_user_id'] = $ownerUser;
    }

    fc_update_carrier($carrierId, $fields);
}

/**
 * Apply a squadronCarrier payload.
 *
 * The shape is `/fleetcarrier` without market, ships, modules or commodities,
 * so the existing appliers do the work; the ones whose block is absent return
 * without touching anything. What is not shared is ownership, which is the
 * whole reason this is not simply fc_ingest_capi.
 */
function fc_squadron_apply(int $carrierId, array $squadron, array $sc, string $ts): void
{
    fc_squadron_claim($carrierId, $squadron);

    $carrier = fc_carrier($carrierId);
    if ($carrier === null) {
        return;
    }
    if ($carrier['capi_at'] !== null && strcmp((string) $carrier['capi_at'], $ts) > 0) {
        return;   // something newer already applied
    }

    $callsign = isset($sc['name']['callsign']) ? strtoupper((string) $sc['name']['callsign']) : null;

    fc_capi_apply_carrier($carrierId, $carrier, $sc, $ts, $callsign);
    fc_squadron_apply_capacity($carrierId, $carrier, $sc, $ts);
    // No market block means no market.services, so servicesCrew is the only
    // thing that can say which services a squadron carrier has at all.
    fc_capi_apply_services_crew($carrierId, $sc, $ts);
    fc_capi_apply_cargo($carrierId, $carrier, $sc, $ts);
    fc_capi_apply_orders($carrierId, $sc, $ts);
    fc_capi_apply_itinerary($carrierId, $sc);
}

/**
 * Hold and crew space, which for a squadron carrier has no other source.
 *
 * A personal carrier gets these from CarrierStats, written when its owner opens
 * the management screen. Nobody opens that screen for a squadron carrier they
 * do not own, so without this a Javelin's capacity would stay empty for ever --
 * and it is not a Drake's 25,000 t, so leaving it to a default would be worse
 * than leaving it blank.
 *
 * Deferred to the journal whenever the journal has spoken more recently, like
 * every other field that has two sources.
 */
function fc_squadron_apply_capacity(int $carrierId, array $carrier, array $sc, string $ts): void
{
    $capacity = $sc['capacity'] ?? null;
    if (!is_array($capacity) || fc_stale($carrier, 'stats_at', $ts)) {
        return;
    }

    static $columns = [
        'space_shippacks' => 'shipPacks',
        'space_modulepacks' => 'modulePacks',
        'space_cargo' => 'cargoForSale',
        'space_reserved' => 'cargoSpaceReserved',
        'space_crew' => 'crew',
        'space_free' => 'freeSpace',
    ];

    $fields = [];
    $total = 0;
    foreach ($columns as $column => $key) {
        if (isset($capacity[$key]) && is_numeric($capacity[$key])) {
            $fields[$column] = (int) $capacity[$key];
            $total += (int) $capacity[$key];
        }
    }
    if ($fields === []) {
        return;
    }

    // Frontier reports the parts, never the whole. They sum to the total by
    // construction, which is how a Javelin's ~57,000 t is arrived at without
    // hardcoding a figure for a hull this board has never seen.
    if (count($fields) === count($columns)) {
        $fields['capacity'] = $total;
    }
    // Deliberately not stats_at: that timestamp means CarrierStats was seen,
    // and claiming it here would make the journal defer to us for ever after.
    $fields['cargo_at'] = $ts;

    fc_update_carrier($carrierId, $fields);
}

