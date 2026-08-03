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

/**
 * The application root — the directory the page scripts live in, one above
 * this one. Anything that reads a file belonging to the deployment rather than
 * to the library uses this, because __DIR__ in here is `lib/`.
 */
define('FC_ROOT', dirname(__DIR__));

require_once __DIR__ . '/costs.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mail.php';

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

/**
 * A link into this app, without the `.php`.
 *
 * nginx maps /fc/settings onto settings.php, so nothing here needs to name the
 * file. Callers still pass `settings.php` — the extension is the honest name of
 * the thing on disk, and stripping it in one place beats remembering not to
 * type it in fifty.
 *
 * Only a trailing `.php` goes, so `assets/style.css` and anything with a path
 * after it are left alone.
 */
function fc_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $path = preg_replace('~^index\.php(?=$|\?)~', '', $path) ?? $path;
    $path = preg_replace('~\.php(?=$|\?)~', '', $path) ?? $path;
    return fc_base_url() . '/fc/' . $path;
}

/**
 * Send `/fc/thing.php` to `/fc/thing`, so the address bar settles on one form.
 *
 * Only GETs, because a redirect is no way to treat a form submission, and not
 * every page: capi.php is registered with Frontier as an exact OAuth
 * redirect_uri, and api.php is the address already-installed EDMC plugins post
 * to. Neither can move, whatever the address bar would prefer.
 */
function fc_canonicalise_url(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
    if (!str_ends_with($path, '.php') || in_array(basename($path), ['capi.php', 'api.php'], true)) {
        return;
    }

    $clean = substr($path, 0, -4);
    if (str_ends_with($clean, '/index')) {
        $clean = substr($clean, 0, -5);
    }
    $query = parse_url($uri, PHP_URL_QUERY);

    // 302, not 301. A permanent redirect is cached by the browser for good, and
    // this is a presentation choice that ought to stay reversible.
    header('Location: ' . $clean . ($query === null || $query === '' ? '' : '?' . $query), true, 302);
    exit;
}

/**
 * The code that grants admin, from `.htadmin-code` in the app root or from
 * FC_ADMIN_CODE.
 *
 * Admin can read and take over any carrier on the board, so it is granted by
 * proving filesystem access to the deployment rather than by being the first
 * to reach the registration form. Delete the file to close the door.
 *
 * The `.ht` prefix is not decoration: nginx on this host denies any path
 * containing `/\.ht`, so the file cannot be fetched over HTTP. A plain
 * `.admin-code` would be served as a static file and hand the code out.
 */
function fc_admin_code(): ?string
{
    $env = fc_env('FC_ADMIN_CODE');
    if ($env !== null) {
        return $env;
    }
    $raw = @file_get_contents(FC_ROOT . '/.htadmin-code');
    if ($raw === false) {
        return null;
    }
    $raw = trim($raw);
    return $raw === '' ? null : $raw;
}

/**
 * The registered Frontier Auth client id.
 *
 * Frontier asks that keys stay out of open source, so this comes from the
 * environment or from `.htcapi-client` in the app root -- the same `.ht`
 * handling as the admin code and the SMTP password, which nginx here refuses
 * to serve.
 */
function fc_capi_client_id(): ?string
{
    $env = fc_env('FC_CAPI_CLIENT_ID');
    if ($env !== null) {
        return $env;
    }
    $raw = @file_get_contents(FC_ROOT . '/.htcapi-client');
    if ($raw === false) {
        return null;
    }
    $raw = trim($raw);
    return $raw === '' ? null : $raw;
}

function fc_capi_configured(): bool
{
    return fc_capi_client_id() !== null;
}

// ---------------------------------------------------------------------------
// Maintenance mode
// ---------------------------------------------------------------------------

/**
 * Where the maintenance state lives.
 *
 * A file, not a database row, and that is the whole design. Maintenance is
 * most wanted precisely when the database is the thing being worked on, and a
 * switch that needs a working database to turn off is not a switch. This one
 * can be lifted with `rm` over SSH whatever else is broken.
 *
 * The `.ht` prefix keeps nginx from serving it, same as the admin code.
 */
function fc_maintenance_file(): string
{
    return FC_ROOT . '/.htmaintenance';
}

/**
 * The maintenance notice, or null when the site is open.
 *
 * @return ?array{message:string,since:?int}
 */
function fc_maintenance(): ?array
{
    static $cached = false;
    static $state = null;

    if ($cached) {
        return $state;
    }
    $cached = true;

    $path = fc_maintenance_file();
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $state = null;
    }

    $message = trim($raw);
    return $state = [
        'message' => $message === '' ? 'The board is down for maintenance. It will be back shortly.' : $message,
        'since' => @filemtime($path) ?: null,
    ];
}

function fc_maintenance_set(?string $message): bool
{
    $path = fc_maintenance_file();
    if ($message === null) {
        return @unlink($path) || !file_exists($path);
    }
    return @file_put_contents($path, trim($message), LOCK_EX) !== false;
}

/**
 * Stop ordinary requests while maintenance is on.
 *
 * Runs automatically when core.php is included, rather than being called at
 * the top of each page: nine entry points and a guard that has to be on every
 * one of them is a guard that will eventually be missing from one.
 *
 * Three ways past it, in order of how much has to be working:
 *
 *   1. Be an admin. Costs a session and a database.
 *   2. Go to /fc/admin. It and the sign-in page stay reachable for exactly
 *      this reason: an admin signed out when it started, or who signs out
 *      during it, would otherwise have no way back in through the site at all.
 *      Being signed out there is fine -- fc_require_user sends them to sign in
 *      with `next` pointing back at the panel.
 *   3. Delete the file. Needs nothing but filesystem access, and works when
 *      the database is gone.
 *
 * The closed sign deliberately advertises none of this. Anyone who ought to be
 * getting in already knows where the door is.
 */
function fc_maintenance_guard(): void
{
    if (PHP_SAPI === 'cli') {
        return;   // cron still has work to do while the doors are shut
    }

    $state = fc_maintenance();
    if ($state === null) {
        return;
    }

    // The staff entrance: the panel itself, and signing in and out so that
    // reaching it is possible while signed out. Everything else on the account
    // page -- register, password reset -- stays shut, since none of it is a way
    // back in for an administrator and all of it writes.
    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script === 'admin.php') {
        return;   // its own admin check decides who actually gets in
    }
    // Signing *in* only. Signing out is shut along with everything else, so a
    // stray click on a stale tab cannot end a session while the board is
    // closed: nobody should come back from maintenance to find themselves
    // logged out. Nothing here ends a session on its own, so anyone signed in
    // when it started is still signed in when it lifts.
    $do = (string) ($_GET['do'] ?? 'login');
    if ($script === 'account.php' && $do === 'login') {
        return;
    }

    // Asking who this is needs the database, which may be the very thing being
    // repaired. If it cannot answer, nobody is an admin and everybody waits.
    //
    // An API key counts as identification here, not just a session cookie. The
    // EDMC plugin has no session, so a session-only test made maintenance stop
    // it uploading -- silently, since nothing on the plugin's side says why the
    // board has gone quiet. An admin's key gets the same pass their browser
    // does, and the game carries on feeding the board while the site is shut.
    try {
        $user = fc_user();
        if ($user === null) {
            $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
            if ($key !== '') {
                $user = fc_user_by_api_key($key);
            }
        }
    } catch (Throwable $e) {
        $user = null;
    }
    if ($user !== null && (int) $user['is_admin'] === 1) {
        return;
    }

    // Everyone else is shut out, but an upload is not turned away -- it is
    // taken and set aside. Refusing one does not delay it, it loses it: the
    // game carries on regardless and the board ends up with a gap.
    if ($user !== null && fc_maintenance_spool($user)) {
        // Never reached; fc_maintenance_spool answers 202 and exits.
        return;
    }

    fc_maintenance_page($state);
}

/**
 * Take an upload for later instead of refusing it.
 *
 * Only ingest requests, and only from an account we could identify. Everything
 * else about the site is readable again in a few minutes and can simply be
 * asked for again; an upload cannot, because whoever sent it has moved on.
 *
 * Answers 202 rather than 200: the data is accepted but nothing has been
 * applied yet, and a client that treats 2xx as success will not retry -- which
 * is what we want, since a retry would spool a second copy.
 *
 * Never returns when it spools something.
 */
function fc_maintenance_spool(array $user): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }

    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $isApi = $script === 'api.php' && ($_GET['action'] ?? '') === 'ingest';
    $isWeb = $script === 'upload.php';
    if (!$isApi && !$isWeb) {
        return false;
    }

    // A browser form still has to prove it meant it. The API path is
    // authenticated by a key it had to be given, which is proof enough.
    if ($isWeb) {
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || !hash_equals(fc_csrf(), $sent)) {
            return false;
        }
    }

    require_once __DIR__ . '/spool.php';

    $chunks = fc_spool_request_bodies();
    if ($chunks === []) {
        return false;
    }

    $taken = 0;
    foreach ($chunks as [$name, $body]) {
        if (fc_spool_add($user, $isApi ? 'api' : 'web', $name, $body)) {
            $taken++;
        }
    }

    if ($taken === 0) {
        return false;   // spool full or unwritable; fall through to the closed sign
    }

    if ($isWeb) {
        fc_flash($taken . ' file' . ($taken === 1 ? '' : 's')
            . ' received and queued. They will be applied when maintenance finishes.');
        fc_redirect(fc_url('upload.php'));
    }

    http_response_code(202);
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    echo json_encode([
        'queued' => $taken,
        'eventsSeen' => 0,
        'eventsApplied' => 0,
        'carriers' => [],
        'notes' => ['The board is down for maintenance. Your upload was queued and will be applied when it finishes.'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function fc_maintenance_page(array $state): never
{
    http_response_code(503);
    header('Retry-After: 600');
    // Cloudflare sits in front and caches by URL; a cached 503 would outlive
    // the maintenance itself.
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (fc_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $state['message'], 'maintenance' => true], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* Stays noindex now the rest of the board is not: this is a temporary
         state, and the one page that must never be what a search turns up. */ ?>
<meta name="robots" content="noindex, nofollow">
<title>Down for maintenance · Carrier Ops</title>
<link rel="icon" type="image/svg+xml" href="/fc/assets/icon.svg">
<link rel="stylesheet" href="/fc/assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= fc_e(fc_url()) ?>">
    <?= fc_logo_svg(34, 'nav', false) ?>
    <span>Carrier&nbsp;Ops</span>
  </a>
</header>
<main class="wrap narrow">
  <div class="card">
    <h1>Down for maintenance</h1>
    <p class="muted"><?= nl2br(fc_e($state['message'])) ?></p>
    <?php if ($state['since'] !== null): ?>
      <p class="small dim">Since <?= fc_e(gmdate('Y-m-d H:i', $state['since'])) ?> UTC.</p>
    <?php endif; ?>
  </div>
</main>
<footer class="foot">
  <span>Carrier Ops</span>
</footer>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/**
 * Uploads one account may make in a minute.
 *
 * Generous, because the honest heavy user is a journal backfill: the EDMC
 * plugin batches two hundred events per file and sends them as fast as its
 * worker manages, so a first-time upload of a year of journals is a burst of
 * real requests that must not be mistaken for an attack.
 */
const FC_INGEST_PER_MINUTE = 30;

/**
 * Refuse an upload when this account has been making too many.
 *
 * Per account rather than per address, which is what the layers in front of
 * this cannot do. Cloudflare and nginx both think in IP addresses, and an
 * authenticated client uploading from one address looks entirely legitimate to
 * them -- while a single account can hold every PHP worker on the host, since
 * there are five for the whole domain and a large upload occupies one for
 * seconds at a time.
 *
 * fc_uploads already records every upload with an account and a timestamp, so
 * the question costs one indexed count and no new schema.
 */
function fc_require_upload_quota(array $user): void
{
    $recent = (int) (fc_one(
        'SELECT COUNT(*) AS n FROM fc_uploads
          WHERE user_id = :id AND ts > (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)',
        ['id' => $user['id']],
    )['n'] ?? 0);

    if ($recent < FC_INGEST_PER_MINUTE) {
        return;
    }

    // 429 with Retry-After, so a well-behaved client waits rather than
    // hammering harder. The EDMC plugin reports the status and carries on.
    http_response_code(429);
    header('Retry-After: 60');
    if (fc_wants_json()) {
        fc_json(429, [
            'error' => 'Too many uploads. Wait a minute and try again.',
            'limit' => FC_INGEST_PER_MINUTE,
            'per' => 'minute',
        ]);
    }
    fc_flash('That is a lot of uploads at once. Give it a minute.', 'err');
    fc_redirect(fc_url('upload.php'));
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

/**
 * A week count in units a person can picture.
 *
 * A well-funded carrier runs to hundreds of weeks, which stops meaning
 * anything past about a year, so say it in months or years instead. Returns
 * null below two months, where the week count is already the clearest form.
 */
function fc_weeks_span(?int $weeks): ?string
{
    if ($weeks === null || $weeks < 9) {
        return null;
    }
    if ($weeks < 52) {
        $months = (int) round($weeks / 4.345);
        return $months . ' month' . ($months === 1 ? '' : 's');
    }

    $years = $weeks / 52.18;   // mean weeks per calendar year
    // One decimal is worth having at 1.4 years; at 14 it is noise. Trailing
    // zeros go, so 104 weeks reads "2 years" rather than "2.0 years".
    $figure = $years < 10
        ? rtrim(rtrim(number_format($years, 1), '0'), '.')
        : number_format($years, 0);

    return $figure . ' year' . ($figure === '1' ? '' : 's');
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
    fc_touch_active($user);
    return $user;
}

/**
 * How long between writes recording that somebody is still here.
 *
 * A page view is not worth a write of its own. At a minute, a session of any
 * length costs about as many updates as it lasts in minutes, and the answer is
 * never more than a minute out -- which is finer than anything reading it
 * cares about, since it is displayed as "4m ago".
 */
const FC_ACTIVE_THROTTLE = 60;

/**
 * Note that this account is currently using the site.
 *
 * Only ever reached through fc_user, which resolves a browser cookie. The
 * plugin authenticates with an API key and never comes this way, so an upload
 * arriving from a machine whose owner is asleep does not count as them being
 * online -- which is the whole distinction this column exists to draw.
 */
function fc_touch_active(array $user): void
{
    $last = $user['last_active'] ?? null;
    if ($last !== null && time() - (int) strtotime((string) $last . ' UTC') < FC_ACTIVE_THROTTLE) {
        return;
    }

    fc_exec('UPDATE fc_users SET last_active = UTC_TIMESTAMP() WHERE id = :id', ['id' => $user['id']]);
}

function fc_require_user(): array
{
    $user = fc_user();
    if ($user === null) {
        if (fc_wants_json()) {
            fc_json(401, ['error' => 'Sign in to continue', 'needsAuth' => true]);
        }
        fc_redirect(fc_url('account.php?do=login&next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/fc/')));
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
 * The owner always can. A squadron carrier's members always can too: it is the
 * squadron's shared asset, not one member's, so membership is the whole test.
 * Everyone else is subject to the carrier's own privacy switches, and a fully
 * private carrier is invisible outside the overview.
 */
function fc_can_view(?array $user, array $carrier, string $tab): bool
{
    if (fc_owns($user, $carrier)) {
        return true;
    }
    if (fc_squadron_membership($user, $carrier) !== null) {
        return true;
    }
    if ((int) $carrier['is_public'] !== 1) {
        return false;
    }
    return match ($tab) {
        'market', 'shipyard', 'outfitting' => (int) $carrier['show_market'] === 1,
        'itinerary' => (int) $carrier['show_itinerary'] === 1,
        // A personal carrier never shows these: the market is what its owner
        // chose to advertise, while the hold and the bank balance are nobody
        // else's business. A squadron's books belong to the squadron
        // collectively, so its owner is allowed to publish them -- which is the
        // one place squadron carriers do not follow the personal rules.
        'finance', 'cargo' => fc_is_squadron_carrier($carrier)
            && (int) ($carrier['show_finance'] ?? 0) === 1,
        default => true,
    };
}

// ---------------------------------------------------------------------------
// Squadron access
// ---------------------------------------------------------------------------
//
// Who may see and manage a squadron carrier. Kept here rather than in
// squadron.php because it is access control, which belongs beside fc_owns, and
// because every page asks it while only the sync routes need the Frontier half.

/**
 * Is this row a squadron carrier?
 *
 * The squadron id is the marker. Everything else about the row -- the id, the
 * jumps, the ledger -- is an ordinary carrier, which is the point: a Javelin is
 * not a separate kind of thing to track, only a differently owned one.
 */
function fc_is_squadron_carrier(array $carrier): bool
{
    return ($carrier['squadron_id'] ?? null) !== null;
}

/**
 * Is rank $a senior to rank $b?
 *
 * Elite numbers squadron ranks from 0 at the top, so smaller is stronger. -1 is
 * this board's own marker for "in the squadron, rank unknown", and loses to
 * every real rank.
 */
function fc_rank_outranks(int $a, int $b): bool
{
    if ($a < 0) {
        return false;
    }
    if ($b < 0) {
        return true;
    }
    return $a < $b;
}

/**
 * This account's squadron memberships, as squadron_id => best rank held.
 *
 * Cached per request: it is asked once per carrier while rendering a fleet
 * view, and the answer cannot change inside one page.
 *
 * @return array<int,int>
 */
function fc_user_squadrons(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $out = [];
    foreach (fc_all(
        'SELECT squadron_id, rank_id FROM fc_squadron_members WHERE user_id = :u',
        ['u' => $userId],
    ) as $row) {
        $squadron = (int) $row['squadron_id'];
        $rank = (int) $row['rank_id'];
        // An account may hold several links into one squadron. The strongest
        // rank wins, which is the only reading that does not let adding a link
        // take access away.
        if (!isset($out[$squadron]) || fc_rank_outranks($rank, $out[$squadron])) {
            $out[$squadron] = $rank;
        }
    }

    return $cache[$userId] = $out;
}

/**
 * This viewer's membership of the carrier's squadron, or null.
 *
 * Costs nothing for a personal carrier: squadron_id is null on every one of
 * them, so the lookup is never reached.
 *
 * @return array{squadron_id:int,rank_id:int}|null
 */
function fc_squadron_membership(?array $user, array $carrier): ?array
{
    if ($user === null || !fc_is_squadron_carrier($carrier)) {
        return null;
    }
    $squadron = (int) $carrier['squadron_id'];
    $held = fc_user_squadrons((int) $user['id']);

    return isset($held[$squadron])
        ? ['squadron_id' => $squadron, 'rank_id' => $held[$squadron]]
        : null;
}

/**
 * Ranks the owner has delegated management to, as rank ids.
 *
 * @return int[]
 */
function fc_manage_ranks(array $carrier): array
{
    $raw = trim((string) ($carrier['manage_ranks'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $out = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $out[] = (int) $part;
        }
    }
    return array_values(array_unique($out));
}

/**
 * May this viewer change the carrier's settings?
 *
 * The owner always may. Beyond that a squadron carrier's owner can delegate to
 * ranks -- and granting a rank grants everyone at least that senior, since
 * "officers may manage this" that excludes the leader is not what anyone means
 * by it.
 */
function fc_can_manage(?array $user, array $carrier): bool
{
    if (fc_owns($user, $carrier)) {
        return true;
    }

    $membership = fc_squadron_membership($user, $carrier);
    if ($membership === null) {
        return false;
    }

    foreach (fc_manage_ranks($carrier) as $granted) {
        if ($membership['rank_id'] === $granted || fc_rank_outranks($membership['rank_id'], $granted)) {
            return true;
        }
    }
    return false;
}

/**
 * Every squadron carrier this account can see but does not own.
 *
 * Owned ones are left out because the dashboard already has those from
 * owner_user_id; this is the set that membership alone earns.
 *
 * @return array<int,array>
 */
function fc_squadron_carriers_for_user(int $userId): array
{
    $held = fc_user_squadrons($userId);
    if ($held === []) {
        return [];
    }

    // The ids come from our own table and are cast to int, so interpolating
    // them is safe; PDO cannot bind a list to one placeholder.
    $ids = implode(',', array_map('intval', array_keys($held)));

    return fc_all(
        "SELECT * FROM fc_carriers
          WHERE squadron_id IN ({$ids})
            AND (owner_user_id IS NULL OR owner_user_id <> :u)
          ORDER BY updated_at DESC",
        ['u' => $userId],
    );
}

/**
 * Carriers for the dashboard: the account's own, plus its squadrons'.
 *
 * @return array<int,array>
 */
function fc_dashboard_carriers(array $user): array
{
    return array_merge(
        fc_all(
            'SELECT * FROM fc_carriers WHERE owner_user_id = :uid ORDER BY updated_at DESC',
            ['uid' => $user['id']],
        ),
        fc_squadron_carriers_for_user((int) $user['id']),
    );
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------

/**
 * Page chrome.
 *
 * These pages are indexable. They were not while the board was unlisted during
 * development; what a crawler now finds is what a signed-out visitor finds,
 * which is the landing page, the public carrier list, and the public parts of
 * whichever carriers their owners chose to publish. Everything behind
 * fc_can_view stays behind it -- being indexable changes what is advertised,
 * not what is readable.
 *
 * Two things stay noindex for reasons of their own: the maintenance holding
 * page, which is a temporary state nobody should find in a search result, and
 * fc_json, because an API response is not a page.
 */
function fc_head(string $title, string $active = ''): void
{
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
<title><?= fc_e($title) ?> · Carrier Ops</title>
<?php
/*
 * Two marks, because one cannot do both jobs. The photograph is a real
 * Drake-Class and looks like one at 128px and up, which is what a link
 * preview or an apple-touch-icon gets. It turns to mush by 32px, so the
 * favicon stays vector.
 */
?>
<link rel="icon" type="image/svg+xml" href="/fc/assets/icon.svg?v=<?= fc_e(fc_asset_version('icon.svg')) ?>">
<link rel="apple-touch-icon" href="/fc/assets/carrier-512.jpg?v=<?= fc_e(fc_asset_version('carrier-512.jpg')) ?>">
<meta property="og:title" content="<?= fc_e($title) ?> · Carrier Ops">
<meta property="og:description" content="Fleet carrier management for Elite Dangerous, read from your own game journals.">
<meta property="og:type" content="website">
<meta property="og:image" content="<?= fc_e(fc_base_url()) ?>/fc/assets/carrier-banner.jpg?v=<?= fc_e(fc_asset_version('carrier-banner.jpg')) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="stylesheet" href="/fc/assets/style.css?v=<?= fc_e(fc_asset_version()) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= fc_e(fc_url()) ?>">
    <?= fc_logo_svg(34, 'nav', false) ?>
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
      <a class="btn ghost sm" href="<?= fc_e(fc_url('account.php?do=logout')) ?>">Sign out</a>
    <?php else: ?>
      <a class="btn ghost sm" href="<?= fc_e(fc_url('account.php?do=login')) ?>">Sign in</a>
    <?php endif; ?>
  </div>
</header>
<?php
    // Shown on every page rather than only where it bites, so the reason an
    // upload will be refused is on screen before it is attempted.
    if ($user !== null && !fc_account_linked($user)) {
        ?>
<div class="verifybar">
  Connect your Frontier account to claim your carrier and upload journals.
  <a href="<?= fc_e(fc_url('capi.php')) ?>">Connect now</a>
</div>
<?php
    }

    // Admins only, and tested explicitly rather than inferred from "they got
    // here". The sign-in page is deliberately left open during maintenance, so
    // signed-out visitors reach fc_head too -- and showing them a notice that
    // the site is shut, with a link offering to turn it off, told anyone who
    // wandered past something that is none of their business.
    if ($user !== null && (int) $user['is_admin'] === 1 && fc_maintenance() !== null) {
        ?>
<div class="maintbar">
  <strong>Maintenance mode is on.</strong> Everyone but admins sees a closed sign.
  <a href="<?= fc_e(fc_url('admin.php')) ?>">Turn it off</a>
</div>
<?php
    }
}

/**
 * Has this account proved who it is, by authorising with Frontier?
 *
 * Ownership used to be an assertion: uploading a journal containing a
 * carrier's owner-only events claimed it, and a journal is a text file anyone
 * can write. Authorising with Frontier is the only proof available that does
 * not rely on trusting the uploader, so it is what the board now asks for.
 *
 * Two deliberate exemptions. An admin is never locked out, because the way
 * back in from a mistake here is through the site itself. And where no client
 * id is configured there is nothing to authorise against, so the check would
 * be a door with no key rather than a gate.
 *
 * A lapsed link still counts. Ownership was proved when it was made, and the
 * carrier rows record it; re-authorising is only needed to read fresh data
 * from Frontier, which is its own separate prompt.
 */
function fc_account_linked(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    if ((int) ($user['is_admin'] ?? 0) === 1 || !fc_capi_configured()) {
        return true;
    }
    return fc_one(
        'SELECT user_id FROM fc_capi_tokens WHERE user_id = :u',
        ['u' => $user['id']],
    ) !== null;
}

/**
 * Refuse an action until the account has been linked to Frontier.
 *
 * Uploading is the line, not signing in: an account that cannot reach any page
 * cannot reach the one that fixes it, and everything read-only stays open.
 */
function fc_require_link(array $user): void
{
    if (fc_account_linked($user)) {
        return;
    }
    if (fc_wants_json()) {
        fc_fail(403, 'Connect your Frontier account before uploading. '
            . 'Sign in and open ' . fc_url('capi.php') . ' to do it.');
    }
    fc_flash('Connect your Frontier account first — that is what proves the carrier is yours.', 'err');
    fc_redirect(fc_url('capi.php'));
}

function fc_foot(): void
{
    ?>
<footer class="foot">
  <span>Carrier Ops</span>
  <span class="muted">Data comes from your own Elite Dangerous journals. Not affiliated with Frontier Developments.</span>
</footer>
</body>
</html>
<?php
}

/**
 * Cache-bust an asset on content change without a build step.
 *
 * Cloudflare caches by URL and will happily keep serving a replaced file
 * forever otherwise — it did exactly that with the plugin zip.
 */
function fc_asset_version(string $file = 'style.css'): string
{
    $mtime = @filemtime(FC_ROOT . '/assets/' . $file);
    return $mtime === false ? '0' : (string) $mtime;
}

/**
 * The mark: a Drake-Class carrier in profile.
 *
 * Drawn from the silhouette that actually identifies one — a long flat flight
 * deck with the command tower set well back, a blunt prow, and the engine block
 * behind. Kept to three solid shapes because it has to survive being a 16px
 * favicon; anything finer turns to porridge at that size. The two notches in
 * the deck are landing pads, and are the first thing to disappear when small,
 * which is fine — the slab-and-tower shape is what carries the recognition.
 *
 * @param string $idSuffix unique per use, so two copies on one page do not
 *                         share a gradient id
 */
function fc_logo_svg(int $size = 32, string $idSuffix = 'brand', bool $plate = true): string
{
    $gradient = 'fclogo-' . $idSuffix;

    // Without the plate, crop to the artwork so the carrier fills the space
    // instead of floating in the padding the plate needs. The nav uses this:
    // the plate colour is the panel colour, so there it was an invisible
    // square with a tiny ship rattling around inside it.
    $box = $plate ? '0 0 32 32' : '1.6 2.8 27.4 22.4';
    $height = $plate ? $size : (int) round($size * 22.4 / 27.4);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $box . '" width="' . $size . '" height="' . $height
        . '" role="img" aria-label="Carrier Ops">'
        . '<defs><linearGradient id="' . $gradient . '" x1="0" y1="0" x2="0.7" y2="1">'
        . '<stop offset="0" stop-color="#ffc38a"/>'
        . '<stop offset="0.55" stop-color="#ff9440"/>'
        . '<stop offset="1" stop-color="#f0741a"/>'
        . '</linearGradient></defs>';

    if ($plate) {
        $svg .= '<rect width="32" height="32" rx="7.5" fill="#12161d"/>';
    }

    // Flight deck: flat top, blunt prow to the right, chamfered underside.
    $svg .= '<path d="M4 18.2h18.6l5.4 2.6-3.1 3.4H5.6L4 21.4z" fill="url(#' . $gradient . ')"/>';

    // Command tower, set back over the stern the way the real thing is.
    $svg .= '<path d="M7.1 7.4h6.6l1.3 10.8H5.9z" fill="url(#' . $gradient . ')"/>';

    // Comms mast.
    $svg .= '<rect x="9.7" y="3.4" width="1.5" height="4.2" rx="0.75" fill="#ffc38a"/>';

    // Engine block at the stern, brighter so it reads as thrust.
    $svg .= '<path d="M2.1 19h2v3.6h-2z" fill="#ffd9b3"/>';

    // Landing pads punched out of the deck. Dark on the orange deck either
    // way, so they work with or without the plate behind them.
    $svg .= '<rect x="16.4" y="19.9" width="2.4" height="1.6" rx="0.5" fill="#12161d" opacity="0.6"/>'
        . '<rect x="20" y="19.9" width="2.4" height="1.6" rx="0.5" fill="#12161d" opacity="0.6"/>';

    return $svg . '</svg>';
}

/**
 * The favicon as a data URI.
 *
 * Not used for the `<link rel="icon">` any more: browsers cache a favicon
 * hard, per origin, and a data URI has no URL to version, so a redrawn logo
 * would sit behind the old one indefinitely. assets/icon.svg is served with a
 * mtime query instead. Kept for anywhere an inline copy is genuinely wanted.
 */
function fc_favicon(): string
{
    return 'data:image/svg+xml;base64,' . base64_encode(fc_logo_svg(32, 'icon'));
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

// ---------------------------------------------------------------------------

// Last, so every function above is defined by the time it runs. Placed here
// rather than at the top of each page on purpose: this file is the one thing
// every entry point already includes, and a guard that has to be repeated nine
// times is a guard that will one day be missing from one of them.
fc_canonicalise_url();
fc_maintenance_guard();
