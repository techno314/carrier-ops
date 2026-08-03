<?php

declare(strict_types=1);

/**
 * Holding uploads while the board is closed.
 *
 * An upload refused during maintenance is not an upload delayed, it is an
 * upload lost: the game carries on, the plugin moves to the next batch, and
 * the board ends up with a hole nobody notices until they go looking. So the
 * data is taken at the door and set aside, and applied once the board reopens.
 *
 * On disk rather than in the database, and that is the whole point. Maintenance
 * is most often wanted *because* the database is the thing being worked on, and
 * a queue that needs a working database to accept anything would be empty
 * exactly when it mattered. The spool needs nothing but a writable directory.
 *
 * The `.ht` prefix keeps nginx from serving it, the same as the other secrets:
 * a spooled journal is somebody's flight history sitting in the docroot.
 *
 * Admins are never spooled. They bypass maintenance entirely and their uploads
 * apply immediately -- see fc_maintenance_guard.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Files the spool will hold before it starts refusing. */
const FC_SPOOL_MAX_FILES = 500;

/** And the total it will hold, so a long maintenance cannot fill the disk. */
const FC_SPOOL_MAX_BYTES = 200 * 1024 * 1024;

/** Applied per drain, so one run cannot occupy a worker indefinitely. */
const FC_SPOOL_DRAIN_LIMIT = 25;

/** Anything still here after this was never going to be applied. */
const FC_SPOOL_KEEP_DAYS = 14;

function fc_spool_dir(): string
{
    return FC_ROOT . '/.htspool';
}

/**
 * Set an upload aside for later.
 *
 * The whole thing goes in one file: a JSON header line naming who sent it and
 * what it was, then the body. One write, one file, nothing to get out of step
 * with anything else.
 *
 * @return bool false when the spool is full or unwritable
 */
function fc_spool_add(array $user, string $source, string $filename, string $body): bool
{
    if (trim($body) === '') {
        return false;
    }

    $dir = fc_spool_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('fc: cannot create the spool directory ' . $dir);
        return false;
    }

    [$files, $bytes] = fc_spool_size();
    if ($files >= FC_SPOOL_MAX_FILES || $bytes + strlen($body) > FC_SPOOL_MAX_BYTES) {
        error_log('fc: spool full; refusing an upload from user ' . ($user['id'] ?? '?'));
        return false;
    }

    $header = json_encode([
        'user_id' => (int) $user['id'],
        'source' => $source,
        'filename' => $filename,
        'received_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    // The name sorts by arrival, so draining in filename order replays them in
    // the order they were sent -- which matters, since the ingest guards
    // compare timestamps and a later upload must not be undone by an earlier
    // one arriving after it.
    $path = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.spool';

    // Written beside and moved into place, so a drain running at the same
    // moment never sees half a file.
    $temp = $path . '.part';
    if (@file_put_contents($temp, $header . "\n" . $body, LOCK_EX) === false) {
        error_log('fc: could not write to the spool');
        return false;
    }
    if (!@rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    @chmod($path, 0600);

    return true;
}

/** @return array{0:int,1:int} how many files, and how many bytes */
function fc_spool_size(): array
{
    $files = glob(fc_spool_dir() . '/*.spool') ?: [];
    $bytes = 0;
    foreach ($files as $file) {
        $bytes += (int) @filesize($file);
    }
    return [count($files), $bytes];
}

/**
 * Apply what has been waiting, oldest first.
 *
 * Refuses to run while the board is still closed: the point is to hold these
 * until the work is finished, and applying them mid-migration is the thing the
 * closure existed to prevent.
 *
 * @return array{applied:int,failed:int,events:int}
 */
function fc_spool_drain(int $limit = FC_SPOOL_DRAIN_LIMIT): array
{
    $out = ['applied' => 0, 'failed' => 0, 'events' => 0];

    if (fc_maintenance() !== null) {
        return $out;
    }

    $files = glob(fc_spool_dir() . '/*.spool') ?: [];
    if ($files === []) {
        return $out;
    }
    sort($files);

    require_once __DIR__ . '/ingest.php';

    foreach (array_slice($files, 0, $limit) as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        $split = strpos($raw, "\n");
        $header = $split === false ? null : json_decode(substr($raw, 0, $split), true);
        $body = $split === false ? '' : substr($raw, $split + 1);

        if (!is_array($header) || !isset($header['user_id']) || trim($body) === '') {
            error_log('fc: discarding an unreadable spool file ' . basename($file));
            @unlink($file);
            $out['failed']++;
            continue;
        }

        $user = fc_one('SELECT * FROM fc_users WHERE id = :id', ['id' => (int) $header['user_id']]);
        if ($user === null || (int) $user['is_banned'] === 1) {
            // The account went away, or was suspended, while this waited.
            @unlink($file);
            $out['failed']++;
            continue;
        }

        try {
            $report = fc_ingest_text(
                $body,
                $user,
                (string) ($header['filename'] ?? 'spooled'),
                (string) ($header['source'] ?? 'spool'),
            );
            $out['events'] += (int) $report['applied'];
            $out['applied']++;
            @unlink($file);
        } catch (Throwable $e) {
            // Left in place for the next run rather than thrown away; if it is
            // genuinely poisonous the age sweep will get it eventually.
            error_log('fc: spooled upload ' . basename($file) . ' failed: ' . $e->getMessage());
            $out['failed']++;
        }
    }

    // Anything this old has failed every run since it arrived.
    foreach ($files as $file) {
        if (@filemtime($file) < time() - FC_SPOOL_KEEP_DAYS * 86400) {
            @unlink($file);
        }
    }

    return $out;
}

/**
 * Take the body of an ingest request, whichever way it was sent.
 *
 * Mirrors what api.php and upload.php accept, because the spool has to be able
 * to stand in for either of them.
 *
 * @return array<int,array{0:string,1:string}> filename and body pairs
 */
function fc_spool_request_bodies(): array
{
    $chunks = [];

    if (!empty($_FILES['journals']['name']) && is_array($_FILES['journals']['name'])) {
        foreach ($_FILES['journals']['name'] as $i => $name) {
            if ((int) $_FILES['journals']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            if ((int) ($_FILES['journals']['size'][$i] ?? 0) > FC_MAX_UPLOAD_BYTES) {
                continue;
            }
            $text = @file_get_contents($_FILES['journals']['tmp_name'][$i]);
            if ($text !== false && trim($text) !== '') {
                $chunks[] = [basename((string) $name), $text];
            }
        }
        return $chunks;
    }

    $body = @file_get_contents('php://input');
    if ($body !== false && trim($body) !== '' && strlen($body) <= FC_MAX_UPLOAD_BYTES) {
        $chunks[] = [basename((string) ($_GET['filename'] ?? 'api')), $body];
    }

    return $chunks;
}
