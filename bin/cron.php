<?php

declare(strict_types=1);

/**
 * Periodic work, run from the host's crontab.
 *
 * Everything else here happens because somebody made a request. That is fine
 * for a board people visit, but three things need to happen whether or not
 * anyone is looking:
 *
 *   Webhook retries   a delivery that failed is otherwise only retried on the
 *                     next upload, which may be days away.
 *   Token renewal     Frontier's refresh token rotates on use and expires if
 *                     left alone. If its window is a sliding one, refreshing
 *                     on a schedule keeps a link alive indefinitely; if it is
 *                     absolute, this costs nothing and changes nothing.
 *   Carrier sync      the whole point of linking to Frontier is that the board
 *                     stays current without the game running.
 *
 * Run from the host rather than inside the container, because this image has
 * no cron daemon. The schedule is every fifteen minutes, written the long way
 * because the shorthand for it would close this comment:
 *
 *   0,15,30,45 * * * * docker exec -u 999 <container> php /home/container/www/fc/bin/cron.php
 *
 * Refuses to run over HTTP. nginx will happily serve any .php under www/, so
 * being unreachable cannot rely on where the file sits.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Recover the app's configuration.
 *
 * Pterodactyl sets DB_* and friends as `env[...]` entries in the PHP-FPM pool,
 * which only that pool's workers inherit -- a process started by `docker exec`
 * gets none of it, and there is no writable environment to put them in. So the
 * pool config is read directly. Ugly, but it is the actual source of truth for
 * this deployment, and the alternative is a second copy of the credentials.
 */
(static function (): void {
    if (getenv('DB_HOST') !== false) {
        return;   // already in the environment; nothing to do
    }
    $conf = @file_get_contents('/home/container/php/pool.d/www.conf');
    if ($conf === false) {
        fwrite(STDERR, "cron: cannot read the php-fpm pool config for configuration\n");
        exit(1);
    }
    if (preg_match_all('/^\s*env\[([A-Za-z_][A-Za-z0-9_]*)\]\s*=\s*(.*)$/m', $conf, $matches, PREG_SET_ORDER)) {
        foreach ($matches as [, $key, $value]) {
            $value = trim($value, " \t\"'");
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
})();

// core.php derives FC_ROOT from its own directory, and the guards in lib/ test
// SCRIPT_FILENAME, so point that at something that is not one of them.
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once __DIR__ . '/../lib/core.php';
require_once __DIR__ . '/../lib/capi_auth.php';
require_once __DIR__ . '/../lib/spool.php';

/**
 * Only one run at a time.
 *
 * A sync that takes longer than the interval would otherwise overlap with the
 * next one, and two runs refreshing the same rotating refresh token is exactly
 * how a working link gets destroyed: the first rotation invalidates the token
 * the second is still holding.
 */
$lock = fopen(sys_get_temp_dir() . '/fc-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "cron: another run is still going; skipping\n");
    exit(0);
}

$started = microtime(true);
$verbose = in_array('-v', $argv, true);
$log = static function (string $line) use ($verbose): void {
    if ($verbose) {
        echo gmdate('H:i:s') . '  ' . $line . "\n";
    }
};

$counts = ['webhooks' => 0, 'refreshed' => 0, 'synced' => 0, 'spooled' => 0, 'errors' => 0];

// --- 0. apply anything that arrived while the board was shut ---------------
//
// First, so that a carrier's own uploads are in before the webhooks and the
// Frontier sync describe it -- otherwise the boards announce a state that is
// about to change again a moment later.
try {
    $drained = fc_spool_drain();
    $counts['spooled'] = $drained['applied'];
    $counts['errors'] += $drained['failed'];
    if ($drained['applied'] > 0 || $drained['failed'] > 0) {
        $log('spool: applied ' . $drained['applied'] . ' upload(s), '
            . $drained['events'] . ' events, ' . $drained['failed'] . ' failed');
    }
} catch (Throwable $e) {
    $counts['errors']++;
    error_log('fc cron: spool drain failed: ' . $e->getMessage());
}

// --- 1. deliver anything the queue is still holding ------------------------
try {
    $counts['webhooks'] = fc_webhook_flush();
    $log('webhook queue: sent ' . $counts['webhooks']);
} catch (Throwable $e) {
    $counts['errors']++;
    error_log('fc cron: webhook flush failed: ' . $e->getMessage());
}

// --- 2. Frontier links -----------------------------------------------------
//
// Renewal and sync are separate concerns: a link is worth keeping alive even
// for an account whose carrier has nothing new to say, and fc_capi_access_token
// renews as a side effect of being asked.
if (fc_capi_configured()) {
    // One row per Frontier account linked, not per user: an account may hold
    // several, and each has its own token and its own carrier to fetch.
    $links = fc_all(
        'SELECT t.* FROM fc_capi_tokens t
           JOIN fc_users u ON u.id = t.user_id
          WHERE t.needs_reauth = 0 AND u.is_banned = 0
          ORDER BY t.id',
    );

    foreach ($links as $link) {
        $user = fc_one('SELECT * FROM fc_users WHERE id = :id', ['id' => $link['user_id']]);
        if ($user === null) {
            continue;
        }
        $linkId = (int) $link['id'];

        try {
            // Renew well ahead of expiry rather than at the last moment, so a
            // Frontier outage has several runs to resolve before anything
            // actually lapses.
            $expires = $link['expires_at'] === null ? 0 : (int) strtotime((string) $link['expires_at'] . ' UTC');
            if ($expires < time() + 3600) {
                $refresh = fc_capi_refresh($linkId);
                if ($refresh['error'] === null) {
                    $counts['refreshed']++;
                    $log('renewed token for link ' . $linkId . ' (user ' . $link['user_id'] . ')');
                } else {
                    $counts['errors']++;
                    $log('renewal failed for link ' . $linkId . ': ' . $refresh['error']);
                    continue;   // no point asking for the carrier without a token
                }
            }

            $result = fc_capi_sync($user, $linkId);   // not forced: respects the interval
            if ($result['ok']) {
                $counts['synced']++;
                $log('synced carrier for link ' . $linkId);
            } elseif ($result['error'] !== null) {
                $counts['errors']++;
                $log('sync failed for link ' . $linkId . ': ' . $result['error']);
            }
        } catch (Throwable $e) {
            $counts['errors']++;
            error_log('fc cron: link ' . $linkId . ': ' . $e->getMessage());
        }
    }
}

// --- 3. housekeeping -------------------------------------------------------
try {
    fc_prune();
    fc_exec('DELETE FROM fc_capi_pending WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 HOUR)');
    fc_exec('DELETE FROM fc_password_resets WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)');
    fc_exec('DELETE FROM fc_buyers WHERE fetched_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)');
    $log('housekeeping done');
} catch (Throwable $e) {
    $counts['errors']++;
    error_log('fc cron: housekeeping failed: ' . $e->getMessage());
}

// Deliver anything the sync itself queued. The after-response hook never fires
// here -- there is no response.
try {
    $counts['webhooks'] += fc_webhook_flush();
} catch (Throwable $e) {
    $counts['errors']++;
}

$elapsed = round(microtime(true) - $started, 2);
$summary = sprintf(
    'spooled=%d webhooks=%d refreshed=%d synced=%d errors=%d in %ss',
    $counts['spooled'], $counts['webhooks'], $counts['refreshed'], $counts['synced'],
    $counts['errors'], $elapsed,
);
$log($summary);

// Only worth a line in the log when something happened or something broke, so
// a quiet installation does not fill the log with news of nothing.
if (array_sum($counts) > 0) {
    error_log('fc cron: ' . $summary);
}

flock($lock, LOCK_UN);
fclose($lock);
exit($counts['errors'] > 0 ? 1 : 0);
