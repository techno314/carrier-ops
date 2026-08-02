<?php

declare(strict_types=1);

/**
 * Shared helpers for the fleet carrier management system.
 *
 * Definitions only — a direct HTTP request for this file 404s so it never
 * shows up as a blank 200. Same guard the /go app uses.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_costs.php';
require_once __DIR__ . '/_schema.php';

const FC_SESSION_COOKIE = 'fc_session';
const FC_SESSION_TTL = 2592000;   // 30 days
const FC_COOKIE_PATH = '/fc/';

/** Cap on a single upload, mirroring the 100M nginx/PHP limit with headroom. */
const FC_MAX_UPLOAD_BYTES = 80 * 1024 * 1024;

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

function fc_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function fc_env_or_fail(string $name): string
{
    $value = fc_env($name);
    if ($value === null) {
        // Don't tell the client which variable is missing; log it instead.
        error_log("fc: missing required environment variable {$name}");
        fc_fail(500, 'Server misconfiguration');
    }
    return $value;
}

function fc_base_url(): string
{
    return rtrim(fc_env('PUBLIC_BASE_URL', 'https://grayflare.space'), '/');
}

function fc_url(string $path = ''): string
{
    return fc_base_url() . '/fc/' . ltrim($path, '/');
}

/**
 * Discord sign-in is opt-in because it needs a redirect URI registered in the
 * Discord developer portal (https://grayflare.space/fc/auth.php). Until that
 * exists the button would dead-end, so it stays hidden rather than broken —
 * which is the exact failure this app was written to avoid.
 */
function fc_discord_enabled(): bool
{
    return fc_env('FC_DISCORD_LOGIN') === '1'
        && fc_env('DISCORD_CLIENT_ID') !== null
        && fc_env('DISCORD_CLIENT_SECRET') !== null;
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function fc_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            fc_env_or_fail('DB_HOST'),
            fc_env('DB_PORT', '3306'),
            fc_env_or_fail('DB_NAME'),
        ),
        fc_env_or_fail('DB_USER'),
        fc_env_or_fail('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    // Bring the schema up to date before the caller's first query. $pdo is
    // already assigned, so the migration's own fc_db() calls return above
    // rather than re-entering here.
    fc_ensure_schema();

    return $pdo;
}

/** Run a query and return all rows. */
function fc_all(string $sql, array $params = []): array
{
    $stmt = fc_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Run a query and return the first row, or null. */
function fc_one(string $sql, array $params = []): ?array
{
    $stmt = fc_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function fc_exec(string $sql, array $params = []): int
{
    $stmt = fc_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

function fc_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Bail out in whichever format the caller is speaking. API clients get JSON;
 * a browser gets a readable page rather than a wall of JSON.
 */
function fc_fail(int $status, string $message): never
{
    if (fc_wants_json()) {
        fc_json($status, ['error' => $message]);
    }
    http_response_code($status);
    fc_head('Error');
    echo '<main class="wrap narrow"><div class="card"><h1>' . fc_e((string) $status) . '</h1><p class="muted">'
        . fc_e($message) . '</p><p><a class="btn" href="' . fc_e(fc_url()) . '">Back to the dashboard</a></p></div></main>';
    fc_foot();
    exit;
}

function fc_wants_json(): bool
{
    if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/api.php')) {
        return true;
    }
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function fc_redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function fc_e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Credits, grouped. Nulls render as a dash rather than a misleading zero. */
function fc_cr(int|float|null $n): string
{
    if ($n === null) {
        return '—';
    }
    return number_format((float) $n, 0, '.', ',');
}

function fc_num(int|float|null $n): string
{
    return $n === null ? '—' : number_format((float) $n, 0, '.', ',');
}

/** "3d ago" / "just now" — same shape the dispatch board uses. */
function fc_ago(?string $ts): string
{
    if ($ts === null || $ts === '') {
        return 'never';
    }
    $t = strtotime($ts . ' UTC');
    if ($t === false) {
        return 'unknown';
    }
    $secs = time() - $t;
    if ($secs < 0) {
        return 'in ' . fc_duration(-$secs);
    }
    if ($secs < 60) {
        return 'just now';
    }
    return fc_duration($secs) . ' ago';
}

function fc_duration(int $secs): string
{
    if ($secs < 60) {
        return $secs . 's';
    }
    $mins = intdiv($secs, 60);
    if ($mins < 60) {
        return $mins . 'm';
    }
    $hours = intdiv($mins, 60);
    if ($hours < 24) {
        return $hours . 'h ' . ($mins % 60) . 'm';
    }
    $days = intdiv($hours, 24);
    return $days . 'd ' . ($hours % 24) . 'h';
}

function fc_dt(?string $ts): string
{
    if ($ts === null || $ts === '') {
        return '—';
    }
    $t = strtotime($ts . ' UTC');
    return $t === false ? '—' : gmdate('Y-m-d H:i', $t) . ' UTC';
}

// ---------------------------------------------------------------------------
// Sessions and accounts
// ---------------------------------------------------------------------------

function fc_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * The signed-in user for this request, or null.
 *
 * Sessions are looked up by SHA-256 of the cookie, so a database leak doesn't
 * hand out usable tokens. Not IP-bound — an account should survive a network
 * change.
 */
function fc_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    $raw = $_COOKIE[FC_SESSION_COOKIE] ?? '';
    if ($raw === '') {
        return null;
    }

    $row = fc_one(
        'SELECT u.* FROM fc_sessions s
           JOIN fc_users u ON u.id = s.user_id
          WHERE s.token_hash = :hash AND s.expires_at > UTC_TIMESTAMP()',
        ['hash' => hash('sha256', $raw)],
    );

    // A ban takes effect on the next request without hunting down sessions.
    if ($row === null || (int) $row['is_banned'] === 1) {
        return null;
    }

    $user = $row;
    return $user;
}

function fc_require_user(): array
{
    $user = fc_user();
    if ($user === null) {
        if (fc_wants_json()) {
            fc_json(401, ['error' => 'Sign in to continue', 'needsAuth' => true]);
        }
        fc_redirect(fc_url('login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/fc/')));
    }
    return $user;
}

function fc_start_session(int $userId): void
{
    // A zero here means the caller lost the id somewhere; the session would be
    // written against a user that does not exist and silently never log in.
    if ($userId <= 0) {
        error_log('fc: refusing to start a session for user id ' . $userId);
        fc_fail(500, 'Could not start your session. Try signing in.');
    }

    $raw = fc_token();
    $expires = time() + FC_SESSION_TTL;
    fc_exec(
        'INSERT INTO fc_sessions (user_id, token_hash, expires_at, created_at)
         VALUES (:uid, :hash, :exp, UTC_TIMESTAMP())',
        ['uid' => $userId, 'hash' => hash('sha256', $raw), 'exp' => gmdate('Y-m-d H:i:s', $expires)],
    );
    setcookie(FC_SESSION_COOKIE, $raw, [
        'expires' => $expires,
        'path' => FC_COOKIE_PATH,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    fc_exec('UPDATE fc_users SET last_login = UTC_TIMESTAMP() WHERE id = :id', ['id' => $userId]);
}

function fc_end_session(): void
{
    $raw = $_COOKIE[FC_SESSION_COOKIE] ?? '';
    if ($raw !== '') {
        fc_exec('DELETE FROM fc_sessions WHERE token_hash = :hash', ['hash' => hash('sha256', $raw)]);
    }
    setcookie(FC_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => FC_COOKIE_PATH,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Opportunistic cleanup, called from auth traffic only so it costs nothing on
 * the ingest path and needs no cron entry.
 */
function fc_prune(): void
{
    fc_exec('DELETE FROM fc_sessions WHERE expires_at < UTC_TIMESTAMP()');
}

/** The account behind an API key, or null. Keys are stored hashed. */
function fc_user_by_api_key(string $key): ?array
{
    if ($key === '') {
        return null;
    }
    $row = fc_one(
        'SELECT * FROM fc_users WHERE api_key_hash = :hash',
        ['hash' => hash('sha256', $key)],
    );
    return ($row === null || (int) $row['is_banned'] === 1) ? null : $row;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/**
 * Per-session token, derived from the session cookie rather than stored, so it
 * needs no server state and dies with the session.
 */
function fc_csrf(): string
{
    $raw = $_COOKIE[FC_SESSION_COOKIE] ?? '';
    if ($raw === '') {
        // Logged out forms (login, register) still need a token. Bind it to a
        // short-lived cookie of its own.
        $anon = $_COOKIE['fc_csrf'] ?? '';
        if ($anon === '') {
            $anon = fc_token();
            setcookie('fc_csrf', $anon, [
                'expires' => time() + 3600,
                'path' => FC_COOKIE_PATH,
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['fc_csrf'] = $anon;
        }
        $raw = $anon;
    }
    return hash_hmac('sha256', 'fc-csrf', $raw);
}

function fc_check_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(fc_csrf(), $sent)) {
        fc_fail(400, 'This form expired. Go back, reload the page, and try again.');
    }
}

// ---------------------------------------------------------------------------
// Carrier access
// ---------------------------------------------------------------------------

function fc_carrier(int|string $id): ?array
{
    return fc_one('SELECT * FROM fc_carriers WHERE id = :id', ['id' => $id]);
}

function fc_carrier_by_callsign(string $callsign): ?array
{
    return fc_one('SELECT * FROM fc_carriers WHERE callsign = :cs', ['cs' => strtoupper($callsign)]);
}

/**
 * Patch a carrier row. Column names come from this codebase, never from
 * request input, so interpolating them into the SQL is safe; the values are
 * always bound.
 */
function fc_update_carrier(int $id, array $fields): void
{
    if ($fields === []) {
        return;
    }
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $column => $value) {
        $sets[] = "`{$column}` = :{$column}";
        $params[$column] = $value;
    }
    $sets[] = 'updated_at = UTC_TIMESTAMP()';
    fc_exec('UPDATE fc_carriers SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
}

function fc_owns(?array $user, array $carrier): bool
{
    return $user !== null
        && ((int) $carrier['owner_user_id'] === (int) $user['id'] || (int) $user['is_admin'] === 1);
}

/**
 * Whether a viewer may see a given tab.
 *
 * The owner always can. Everyone else is subject to the carrier's own privacy
 * switches, and a fully private carrier is invisible outside the overview.
 */
function fc_can_view(?array $user, array $carrier, string $tab): bool
{
    if (fc_owns($user, $carrier)) {
        return true;
    }
    if ((int) $carrier['is_public'] !== 1) {
        return false;
    }
    return match ($tab) {
        'market', 'shipyard', 'outfitting' => (int) $carrier['show_market'] === 1,
        'itinerary' => (int) $carrier['show_itinerary'] === 1,
        'finance' => false,   // never public — it is the owner's bank balance
        default => true,
    };
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------

/**
 * Every page is noindex. The site is deliberately unlisted: it is not linked
 * from the landing page and should not turn up in a search.
 */
function fc_head(string $title, string $active = ''): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    $user = fc_user();
    $nav = [
        '' => ['Dashboard', fc_url()],
        'search' => ['Carriers', fc_url('search.php')],
        'upload' => ['Upload', fc_url('upload.php')],
        'settings' => ['Settings', fc_url('settings.php')],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= fc_e($title) ?> · Carrier Ops</title>
<link rel="icon" type="image/svg+xml" href="<?= fc_e(fc_favicon()) ?>">
<link rel="stylesheet" href="/fc/assets/style.css?v=<?= fc_e(fc_asset_version()) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= fc_e(fc_url()) ?>">
    <span class="brand-mark"></span>
    <span>Carrier&nbsp;Ops</span>
  </a>
  <nav>
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <?php if ($key !== '' && $user === null) { continue; } ?>
      <a href="<?= fc_e($href) ?>"<?= $key === $active ? ' class="on"' : '' ?>><?= fc_e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="who">
    <?php if ($user !== null): ?>
      <span class="muted"><?= fc_e($user['username']) ?></span>
      <a class="btn ghost sm" href="<?= fc_e(fc_url('logout.php')) ?>">Sign out</a>
    <?php else: ?>
      <a class="btn ghost sm" href="<?= fc_e(fc_url('login.php')) ?>">Sign in</a>
    <?php endif; ?>
  </div>
</header>
<?php
}

function fc_foot(): void
{
    ?>
<footer class="foot">
  <span>Carrier Ops · unlisted</span>
  <span class="muted">Data comes from your own Elite Dangerous journals. Not affiliated with Frontier Developments.</span>
</footer>
</body>
</html>
<?php
}

/** Cache-bust the stylesheet on content change without a build step. */
function fc_asset_version(): string
{
    $path = __DIR__ . '/assets/style.css';
    $mtime = @filemtime($path);
    return $mtime === false ? '0' : (string) $mtime;
}

function fc_favicon(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        . '<rect width="32" height="32" rx="8" fill="#12161d"/>'
        . '<path d="M6 19h20l-3 5H9z" fill="#ff8a3d"/>'
        . '<path d="M9 12h14l3 6H6z" fill="#ffb066"/>'
        . '<circle cx="16" cy="9" r="2.5" fill="#ff8a3d"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** One-shot banner passed through a redirect. */
function fc_flash(?string $set = null, string $kind = 'ok'): ?array
{
    if ($set !== null) {
        setcookie('fc_flash', $kind . '|' . $set, [
            'expires' => time() + 60,
            'path' => FC_COOKIE_PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return null;
    }
    $raw = $_COOKIE['fc_flash'] ?? '';
    if ($raw === '') {
        return null;
    }
    setcookie('fc_flash', '', [
        'expires' => time() - 3600,
        'path' => FC_COOKIE_PATH,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    [$kind, $message] = array_pad(explode('|', $raw, 2), 2, '');
    return ['kind' => $kind === 'err' ? 'err' : 'ok', 'message' => $message];
}

function fc_render_flash(): void
{
    $flash = fc_flash();
    if ($flash === null) {
        return;
    }
    echo '<div class="banner ' . fc_e($flash['kind']) . '">' . fc_e($flash['message']) . '</div>';
}
