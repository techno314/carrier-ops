<?php

declare(strict_types=1);

/**
 * Talking to Frontier's Auth service ourselves.
 *
 * Until now the board only ever saw Companion API data second-hand, forwarded
 * by the EDMC plugin's `capi_fleetcarrier` hook, because obtaining a client id
 * meant an approval we did not have. With one registered, the board can ask
 * Frontier directly and keep itself current without the game or EDMC running.
 *
 * The app is registered as a *public* client -- Frontier issues no shared key
 * for it -- so this is the PKCE flow. There is no client secret anywhere in
 * this file, and none is needed: what proves the token request came from the
 * same party that started the authorisation is the verifier, which never
 * leaves this server.
 *
 * Frontier's own note on PKCE is worth repeating because it is the step most
 * implementations get wrong: the challenge is Base64URL of the *raw binary*
 * SHA-256 of the verifier, not of its hex digest. fc_capi_challenge does that,
 * and it has been checked against the live service.
 *
 *   https://auth.frontierstore.net/auth    /token    /decode    /me
 *   https://companion.orerve.net/fleetcarrier
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Pulls in capi.php (the payload parser this ultimately feeds) and webhooks.php
// alongside it, so a sync announces itself exactly as an upload would.
require_once __DIR__ . '/ingest.php';

const FC_AUTH_BASE = 'https://auth.frontierstore.net';
const FC_CAPI_BASE = 'https://companion.orerve.net';

/** Scopes we ask for. `auth` identifies the account, `capi` reads the carrier. */
const FC_CAPI_SCOPE = 'auth capi';

/** An authorisation left half-finished is abandoned rather than kept forever. */
const FC_CAPI_PENDING_TTL = 900;

/** Refresh this long before the token actually expires, to avoid racing it. */
const FC_CAPI_REFRESH_MARGIN = 300;

/** Frontier is a third party; a slow reply must not hold one of five workers. */
const FC_CAPI_TIMEOUT = 20;

/** Least time between automatic /fleetcarrier calls for one account. */
const FC_CAPI_MIN_FETCH_INTERVAL = 900;

// fc_capi_client_id() and fc_capi_configured() live in core.php, beside the
// other configuration lookups. Every page needs to know whether linking is
// available in order to draw the nav prompt, and reaching that answer should
// not drag the whole ingest stack in behind it.

/** Must match what is registered with Frontier, exactly. */
function fc_capi_redirect_uri(): string
{
    return fc_url('capi.php');
}

// ---------------------------------------------------------------------------
// Tokens at rest
// ---------------------------------------------------------------------------

/**
 * The key used to encrypt stored tokens, created on first use.
 *
 * Kept beside the other secrets rather than in the database, which is the
 * whole point: the two would have to leak together to be worth anything. If
 * the file is lost, stored tokens become undecryptable and everyone simply
 * authorises again -- an inconvenience, not a disaster, which is the right
 * trade for not holding other people's Frontier credentials in the clear.
 */
function fc_capi_key(): ?string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $path = FC_ROOT . '/.htcapi-key';
    $raw = @file_get_contents($path);
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = base64_decode(trim($raw), true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $key = $decoded;
        }
    }

    $fresh = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    if (@file_put_contents($path, base64_encode($fresh), LOCK_EX) === false) {
        error_log('fc: cannot write ' . $path . '; Frontier tokens cannot be stored');
        return null;
    }
    @chmod($path, 0600);
    return $key = $fresh;
}

function fc_capi_encrypt(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $key = fc_capi_key();
    if ($key === null) {
        return null;
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
}

function fc_capi_decrypt(?string $blob): ?string
{
    if ($blob === null || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $key = fc_capi_key();
    if ($key === null) {
        return null;
    }
    $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = @sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? null : $plain;
}

// ---------------------------------------------------------------------------
// PKCE
// ---------------------------------------------------------------------------

/** Base64 as the OAuth specs want it: URL alphabet, no padding. */
function fc_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * The challenge for a verifier.
 *
 * SHA-256 with `$binary = true`, then Base64URL. Hashing to hex first and
 * encoding that is the common mistake, and Frontier's documentation calls it
 * out specifically.
 */
function fc_capi_challenge(string $verifier): string
{
    return fc_b64url(hash('sha256', $verifier, true));
}

/**
 * Begin an authorisation: remember the verifier, return the URL to send them to.
 */
function fc_capi_start(int $userId): string
{
    // Housekeeping while we are here; no cron entry for something this small.
    fc_exec(
        'DELETE FROM fc_capi_pending WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :ttl SECOND)',
        ['ttl' => FC_CAPI_PENDING_TTL],
    );

    $verifier = fc_b64url(random_bytes(32));
    $state = fc_b64url(random_bytes(24));

    // Only the hash is stored, so a leak of this table cannot be used to
    // complete somebody else's authorisation -- the same reasoning as sessions
    // and password resets.
    fc_exec(
        'INSERT INTO fc_capi_pending (state_hash, user_id, verifier, created_at)
         VALUES (:s, :u, :v, UTC_TIMESTAMP())',
        ['s' => hash('sha256', $state), 'u' => $userId, 'v' => $verifier],
    );

    return FC_AUTH_BASE . '/auth?' . http_build_query([
        'response_type' => 'code',
        'client_id' => fc_capi_client_id(),
        'redirect_uri' => fc_capi_redirect_uri(),
        'scope' => FC_CAPI_SCOPE,
        'state' => $state,
        'code_challenge' => fc_capi_challenge($verifier),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Finish an authorisation.
 *
 * @return array{ok:bool,error:?string}
 */
function fc_capi_complete(string $code, string $state): array
{
    $row = fc_one(
        'SELECT * FROM fc_capi_pending WHERE state_hash = :s',
        ['s' => hash('sha256', $state)],
    );
    if ($row === null) {
        return ['ok' => false, 'error' => 'That sign-in did not match a request from this site. Start again.'];
    }
    // Single use, whatever happens next.
    fc_exec('DELETE FROM fc_capi_pending WHERE state_hash = :s', ['s' => hash('sha256', $state)]);

    if (strtotime((string) $row['created_at'] . ' UTC') < time() - FC_CAPI_PENDING_TTL) {
        return ['ok' => false, 'error' => 'That sign-in took too long and expired. Start again.'];
    }

    $token = fc_capi_token_request([
        'grant_type' => 'authorization_code',
        'client_id' => fc_capi_client_id(),
        'code' => $code,
        'redirect_uri' => fc_capi_redirect_uri(),
        'code_verifier' => (string) $row['verifier'],
    ]);
    if ($token['error'] !== null) {
        return ['ok' => false, 'error' => $token['error']];
    }

    $userId = (int) $row['user_id'];
    fc_capi_store($userId, $token['data']);

    // Identity is fetched once, at link time, and only customer_id and platform
    // are kept. See the note on fc_capi_tokens in schema.php.
    $me = fc_capi_get('/me', $token['data']['access_token'] ?? '', FC_AUTH_BASE);
    if (is_array($me['data'] ?? null)) {
        fc_exec(
            'UPDATE fc_capi_tokens SET customer_id = :c, platform = :p WHERE user_id = :u',
            [
                'c' => isset($me['data']['customer_id']) ? mb_substr((string) $me['data']['customer_id'], 0, 64) : null,
                'p' => isset($me['data']['platform']) ? mb_substr((string) $me['data']['platform'], 0, 32) : null,
                'u' => $userId,
            ],
        );
    }

    return ['ok' => true, 'error' => null];
}

/** Write a token response to the account, encrypting both tokens. */
function fc_capi_store(int $userId, array $data): void
{
    $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in'])
        ? (int) $data['expires_in']
        : 3600;

    fc_exec(
        'INSERT INTO fc_capi_tokens
             (user_id, access_token, refresh_token, scope, expires_at, needs_reauth, last_error, refreshed_at, linked_at)
         VALUES (:u, :a, :r, :s, :e, 0, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
             access_token = VALUES(access_token),
             -- Frontier rotates the refresh token on every exchange, but a
             -- response that omits it must not wipe the one we have.
             refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
             scope = VALUES(scope),
             expires_at = VALUES(expires_at),
             needs_reauth = 0,
             last_error = NULL,
             refreshed_at = UTC_TIMESTAMP()',
        [
            'u' => $userId,
            'a' => fc_capi_encrypt($data['access_token'] ?? null),
            'r' => fc_capi_encrypt($data['refresh_token'] ?? null),
            's' => isset($data['scope']) ? mb_substr((string) $data['scope'], 0, 64) : FC_CAPI_SCOPE,
            'e' => gmdate('Y-m-d H:i:s', time() + $expiresIn),
        ],
    );
}

/**
 * A usable access token for this account, refreshing if it has gone stale.
 *
 * @return array{token:?string,error:?string}
 */
function fc_capi_access_token(int $userId): array
{
    $row = fc_one('SELECT * FROM fc_capi_tokens WHERE user_id = :u', ['u' => $userId]);
    if ($row === null) {
        return ['token' => null, 'error' => 'This account is not linked to Frontier.'];
    }
    if ((int) $row['needs_reauth'] === 1) {
        return ['token' => null, 'error' => 'The Frontier link has expired and needs authorising again.'];
    }

    $expires = $row['expires_at'] === null ? 0 : (int) strtotime((string) $row['expires_at'] . ' UTC');
    if ($expires > time() + FC_CAPI_REFRESH_MARGIN) {
        $token = fc_capi_decrypt($row['access_token']);
        if ($token !== null) {
            return ['token' => $token, 'error' => null];
        }
        // Undecryptable: the key changed underneath us. Refreshing will not
        // help, since the refresh token is encrypted with the same key.
        fc_capi_mark_reauth($userId, 'Stored tokens could not be read.');
        return ['token' => null, 'error' => 'The stored Frontier link could not be read. Authorise again.'];
    }

    return fc_capi_refresh($userId, $row);
}

/**
 * Exchange the refresh token for a new access token.
 *
 * @return array{token:?string,error:?string}
 */
function fc_capi_refresh(int $userId, ?array $row = null): array
{
    $row ??= fc_one('SELECT * FROM fc_capi_tokens WHERE user_id = :u', ['u' => $userId]);
    $refresh = $row === null ? null : fc_capi_decrypt($row['refresh_token']);
    if ($refresh === null) {
        fc_capi_mark_reauth($userId, 'No refresh token stored.');
        return ['token' => null, 'error' => 'The Frontier link needs authorising again.'];
    }

    $token = fc_capi_token_request([
        'grant_type' => 'refresh_token',
        'client_id' => fc_capi_client_id(),
        'refresh_token' => $refresh,
    ]);

    if ($token['error'] !== null) {
        // A refused refresh is the expiry Frontier warns about; there is no
        // recovering from it without the user authorising again.
        if ($token['fatal']) {
            fc_capi_mark_reauth($userId, $token['error']);
            return ['token' => null, 'error' => 'The Frontier link has expired. Authorise again to restore it.'];
        }
        fc_exec('UPDATE fc_capi_tokens SET last_error = :e WHERE user_id = :u',
            ['e' => mb_substr($token['error'], 0, 255), 'u' => $userId]);
        return ['token' => null, 'error' => $token['error']];
    }

    // This is the step that matters: the response carries a *new* refresh
    // token, and keeping the old one would work exactly once more and then
    // fail for good.
    fc_capi_store($userId, $token['data']);

    return ['token' => $token['data']['access_token'] ?? null, 'error' => null];
}

function fc_capi_mark_reauth(int $userId, string $why): void
{
    fc_exec(
        'UPDATE fc_capi_tokens SET needs_reauth = 1, last_error = :e WHERE user_id = :u',
        ['e' => mb_substr($why, 0, 255), 'u' => $userId],
    );
}

function fc_capi_unlink(int $userId): void
{
    fc_exec('DELETE FROM fc_capi_tokens WHERE user_id = :u', ['u' => $userId]);
    fc_exec('DELETE FROM fc_capi_pending WHERE user_id = :u', ['u' => $userId]);
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * POST to /token.
 *
 * `fatal` distinguishes "this grant is dead, tell the user to authorise again"
 * from "the network had a bad moment, try later" -- treating the second as the
 * first would nag people to re-link every time Frontier hiccups.
 *
 * @return array{data:array,error:?string,fatal:bool}
 */
function fc_capi_token_request(array $fields): array
{
    $ch = curl_init(FC_AUTH_BASE . '/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => FC_CAPI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'CarrierOps (+' . fc_base_url() . '/fc/)',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['data' => [], 'error' => 'Could not reach Frontier: ' . ($curlError ?: 'connection failed'), 'fatal' => false];
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return ['data' => [], 'error' => 'Frontier sent an unreadable reply (' . $status . ').', 'fatal' => false];
    }

    if ($status >= 200 && $status < 300 && isset($decoded['access_token'])) {
        return ['data' => $decoded, 'error' => null, 'fatal' => false];
    }

    $code = (string) ($decoded['error'] ?? ('http_' . $status));
    $detail = (string) ($decoded['error_description'] ?? '');

    // invalid_grant is the refresh token being spent or expired. invalid_client
    // and unauthorized_client mean the registration itself is not usable yet --
    // which is what a pending approval looks like from here.
    $fatal = in_array($code, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'access_denied'], true);

    // Frontier's description is not always fit to show. A bad authorisation
    // code comes back as a raw Doctrine exception naming its ORM and the value
    // we sent, which tells the person reading it nothing and repeats their
    // input back at them. Log it in full, show something meaningful instead.
    error_log('fc: Frontier /token refused (' . $status . ') ' . $code . ' ' . $detail);

    $friendly = match (true) {
        $code === 'invalid_grant' => 'Frontier would not accept that authorisation — it may have already been used or expired.',
        $code === 'invalid_client', $code === 'unauthorized_client' =>
            'Frontier does not currently accept this application. If it is still awaiting approval, that is expected.',
        $code === 'access_denied' => 'Frontier declined the request.',
        $detail !== '' && strlen($detail) < 120 && !str_contains($detail, 'Doctrine') => $code . ': ' . $detail,
        default => 'Frontier rejected the request (' . $code . ').',
    };

    return ['data' => [], 'error' => $friendly, 'fatal' => $fatal];
}

/**
 * GET a JSON resource with a bearer token.
 *
 * @return array{data:mixed,status:int,error:?string}
 */
function fc_capi_get(string $path, string $accessToken, string $base = FC_CAPI_BASE): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => FC_CAPI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'CarrierOps (+' . fc_base_url() . '/fc/)',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['data' => null, 'status' => 0, 'error' => $curlError ?: 'connection failed'];
    }
    if ($status < 200 || $status >= 300) {
        return ['data' => null, 'status' => $status, 'error' => 'Frontier returned ' . $status . '.'];
    }

    $decoded = json_decode((string) $body, true);
    return ['data' => $decoded, 'status' => $status, 'error' => null];
}

// ---------------------------------------------------------------------------
// Using it
// ---------------------------------------------------------------------------

/**
 * Fetch /fleetcarrier for an account and apply it.
 *
 * `$force` is a person pressing a button; without it the interval is respected,
 * because this is Frontier's server and a page refresh is not a reason to ask
 * them again.
 *
 * @return array{ok:bool,note:?string,error:?string}
 */
function fc_capi_sync(array $user, bool $force = false): array
{
    $userId = (int) $user['id'];
    $row = fc_one('SELECT * FROM fc_capi_tokens WHERE user_id = :u', ['u' => $userId]);
    if ($row === null) {
        return ['ok' => false, 'note' => null, 'error' => 'This account is not linked to Frontier.'];
    }

    if (!$force && $row['last_fetch_at'] !== null) {
        $age = time() - (int) strtotime((string) $row['last_fetch_at'] . ' UTC');
        if ($age < FC_CAPI_MIN_FETCH_INTERVAL) {
            return ['ok' => false, 'note' => 'Checked ' . fc_ago($row['last_fetch_at']) . '; too soon to ask again.', 'error' => null];
        }
    }

    $access = fc_capi_access_token($userId);
    if ($access['token'] === null) {
        return ['ok' => false, 'note' => null, 'error' => $access['error']];
    }

    $response = fc_capi_get('/fleetcarrier', $access['token']);

    // 204 means the account has no carrier -- not an error, just nothing to do.
    if ($response['status'] === 204 || ($response['error'] === null && $response['data'] === null)) {
        fc_exec('UPDATE fc_capi_tokens SET last_fetch_at = UTC_TIMESTAMP(), last_error = NULL WHERE user_id = :u', ['u' => $userId]);
        return ['ok' => false, 'note' => 'Frontier reports no fleet carrier on this account.', 'error' => null];
    }

    if ($response['error'] !== null) {
        // A 401 here after a good refresh means the grant has been withdrawn.
        if ($response['status'] === 401) {
            fc_capi_mark_reauth($userId, 'Frontier rejected the token.');
            return ['ok' => false, 'note' => null, 'error' => 'Frontier rejected the link. Authorise again.'];
        }
        fc_exec('UPDATE fc_capi_tokens SET last_error = :e WHERE user_id = :u',
            ['e' => mb_substr($response['error'], 0, 255), 'u' => $userId]);
        return ['ok' => false, 'note' => null, 'error' => $response['error']];
    }

    if (!is_array($response['data']) || !fc_is_capi_payload($response['data'])) {
        return ['ok' => false, 'note' => null, 'error' => 'Frontier sent something this does not recognise.'];
    }

    // Straight into the parser the EDMC plugin already feeds, so both routes
    // produce identical state.
    $result = fc_ingest_capi($response['data'], $user);

    fc_exec(
        'UPDATE fc_capi_tokens SET last_fetch_at = UTC_TIMESTAMP(), last_error = NULL WHERE user_id = :u',
        ['u' => $userId],
    );

    if ($result['applied'] && $result['carrier_id'] !== null) {
        fc_fill_itinerary_bodies($result['carrier_id']);
        fc_close_itinerary($result['carrier_id']);
        fc_webhook_check_finance($result['carrier_id']);
        fc_webhook_board_refresh($result['carrier_id']);
        fc_webhook_flush_after_response();
    }

    fc_exec(
        'INSERT INTO fc_uploads (user_id, source, filename, bytes, events_seen, events_applied, carriers_touched, ts)
         VALUES (:uid, :src, :file, 0, 1, :applied, :carriers, UTC_TIMESTAMP())',
        [
            'uid' => $userId,
            'src' => 'capi',
            'file' => '/fleetcarrier',
            'applied' => $result['applied'] ? 1 : 0,
            'carriers' => mb_substr((string) ($result['callsign'] ?? ''), 0, 190),
        ],
    );

    return ['ok' => (bool) $result['applied'], 'note' => $result['note'], 'error' => null];
}

/** The link row for a user, or null. */
function fc_capi_link(int $userId): ?array
{
    return fc_one('SELECT * FROM fc_capi_tokens WHERE user_id = :u', ['u' => $userId]);
}
