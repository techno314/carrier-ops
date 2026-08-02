<?php

declare(strict_types=1);

/**
 * Finding somewhere to sell what the carrier is holding.
 *
 * The board knows what is in the hold and where the carrier is; what it does
 * not know is who wants any of it. That is galaxy-wide market data, gathered
 * from thousands of commanders' journals, and there is no point rebuilding it
 * here — Ardent already does exactly this and answers over a public API with
 * no key.
 *
 *   https://api.ardent-insight.com/v2/commodity/name/{commodity}/imports
 *       ?systemName=...&maxDistance=...&minVolume=...&fleetCarriers=0
 *
 * Every answer is cached, because it is somebody else's server doing the work
 * and a page reload is not a reason to make them do it twice.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const FC_ARDENT_BASE = 'https://api.ardent-insight.com/v2';

/** Long enough to be polite, short enough that a price is still worth acting on. */
const FC_BUYERS_TTL_SECONDS = 6 * 3600;

const FC_BUYERS_TIMEOUT = 20;

/** Beyond this a listing is old enough that the price is a guess. */
const FC_PRICE_STALE_DAYS = 30;

/**
 * Stations near a system that want a commodity, best price first.
 *
 * Fleet carriers are excluded. Their owners set arbitrary prices — the first
 * result for tritium near Jackson's Lighthouse was a carrier offering five
 * million a tonne — and a carrier is not somewhere you can reliably go and
 * sell sixteen thousand tonnes anyway.
 *
 * @return array{rows:array,fetched_at:?string,error:?string,relaxed:bool}
 */
function fc_find_buyers(string $commodity, string $system, int $minDemand, int $maxDistance = 1000): array
{
    $result = fc_ardent_imports($commodity, $system, $minDemand, $maxDistance);

    // Asking for more demand than anyone has is a common way to get nothing
    // back. Rather than an empty page, drop the demand floor and say so.
    if ($result['error'] === null && $result['rows'] === [] && $minDemand > 0) {
        $relaxed = fc_ardent_imports($commodity, $system, 0, $maxDistance);
        if ($relaxed['rows'] !== []) {
            $relaxed['relaxed'] = true;
            return $relaxed;
        }
    }

    return $result;
}

/**
 * @return array{rows:array,fetched_at:?string,error:?string,relaxed:bool}
 */
function fc_ardent_imports(string $commodity, string $system, int $minDemand, int $maxDistance): array
{
    $commodity = strtolower(trim($commodity));
    $hash = sha1(implode('|', [$commodity, strtolower($system), $minDemand, $maxDistance]));

    $cached = fc_one('SELECT * FROM fc_buyers WHERE query_hash = :h', ['h' => $hash]);
    if ($cached !== null
        && strtotime((string) $cached['fetched_at'] . ' UTC') > time() - FC_BUYERS_TTL_SECONDS
    ) {
        $rows = json_decode((string) $cached['payload'], true);
        return [
            'rows' => is_array($rows) ? $rows : [],
            'fetched_at' => $cached['fetched_at'],
            'error' => null,
            'relaxed' => false,
        ];
    }

    $url = FC_ARDENT_BASE . '/commodity/name/' . rawurlencode($commodity) . '/imports?'
        . http_build_query([
            'systemName' => $system,
            'maxDistance' => $maxDistance,
            'minVolume' => $minDemand,
            'fleetCarriers' => 0,
        ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => FC_BUYERS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        // Ardent is run by a volunteer. Say who is calling.
        CURLOPT_USERAGENT => 'CarrierOps/1.0 (+' . fc_base_url() . '/fc/)',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        // Serve whatever we last had rather than nothing, and say how old it is.
        if ($cached !== null) {
            $rows = json_decode((string) $cached['payload'], true);
            return [
                'rows' => is_array($rows) ? $rows : [],
                'fetched_at' => $cached['fetched_at'],
                'error' => null,
                'relaxed' => false,
            ];
        }
        error_log("fc: Ardent lookup failed ({$status}) {$curlError} for {$url}");
        return [
            'rows' => [],
            'fetched_at' => null,
            'error' => 'Could not reach the market data service just now.',
            'relaxed' => false,
        ];
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return ['rows' => [], 'fetched_at' => null, 'error' => 'The market data service sent something unreadable.', 'relaxed' => false];
    }

    $rows = fc_normalise_buyers($decoded);

    fc_exec(
        'INSERT INTO fc_buyers (query_hash, commodity, system, min_demand, max_distance, payload, fetched_at)
         VALUES (:h, :c, :s, :d, :dist, :p, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)',
        [
            'h' => $hash, 'c' => mb_substr($commodity, 0, 64), 's' => mb_substr($system, 0, 128),
            'd' => $minDemand, 'dist' => $maxDistance,
            'p' => json_encode($rows, JSON_UNESCAPED_SLASHES),
        ],
    );

    // Opportunistic housekeeping; no cron entry needed for a cache this small.
    fc_exec('DELETE FROM fc_buyers WHERE fetched_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)');

    return ['rows' => $rows, 'fetched_at' => gmdate('Y-m-d H:i:s'), 'error' => null, 'relaxed' => false];
}

/** Keep only the fields the page shows, and order them usefully. */
function fc_normalise_buyers(array $raw): array
{
    $rows = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !isset($entry['stationName'])) {
            continue;
        }
        // A listed price with no demand behind it is not somewhere you can
        // sell. These turn up once the demand floor is relaxed, and would
        // otherwise sit near the top on price alone offering to buy nothing.
        if ((int) ($entry['demand'] ?? 0) <= 0 || (int) ($entry['sellPrice'] ?? 0) <= 0) {
            continue;
        }
        $rows[] = [
            'station' => (string) $entry['stationName'],
            'system' => (string) ($entry['systemName'] ?? ''),
            'stationType' => (string) ($entry['stationType'] ?? ''),
            'distance' => (float) ($entry['distance'] ?? 0),
            'arrival' => (float) ($entry['distanceToArrival'] ?? 0),
            'pad' => (int) ($entry['maxLandingPadSize'] ?? 0),
            'demand' => (int) ($entry['demand'] ?? 0),
            'sellPrice' => (int) ($entry['sellPrice'] ?? 0),
            'updatedAt' => (string) ($entry['updatedAt'] ?? ''),
        ];
    }

    // Best price first; distance breaks ties, since at equal money the closer
    // one is plainly better.
    usort($rows, static function (array $a, array $b): int {
        return [$b['sellPrice'], $a['distance']] <=> [$a['sellPrice'], $b['distance']];
    });

    return array_slice($rows, 0, 40);
}

/** Whether a listing is old enough that the price should not be trusted. */
function fc_price_is_stale(string $updatedAt): bool
{
    $t = strtotime($updatedAt);
    return $t === false || $t < time() - FC_PRICE_STALE_DAYS * 86400;
}

/** Large / Medium / Small, from Ardent's numeric pad size. */
function fc_pad_label(int $pad): string
{
    return match ($pad) {
        3 => 'L',
        2 => 'M',
        1 => 'S',
        default => '?',
    };
}
