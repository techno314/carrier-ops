<?php

declare(strict_types=1);

/**
 * Sending mail over SMTP.
 *
 * PHP's mail() would hand the message to a local sendmail this container does
 * not really have, and anything it did manage to emit from a Pterodactyl box
 * would be filed as spam. So this speaks SMTP directly to the same relay the
 * Nextcloud instance on the host uses.
 *
 * Settings mirror Nextcloud's, and the names map one to one:
 *
 *   mail_smtphost      FC_SMTP_HOST
 *   mail_smtpport      FC_SMTP_PORT
 *   mail_smtpsecure    FC_SMTP_SECURE      tls (STARTTLS) | ssl | none
 *   mail_smtpname      FC_SMTP_USER
 *   mail_smtppassword  FC_SMTP_PASSWORD    or .htsmtp-password in the app root
 *   mail_from_address
 *     + mail_domain    FC_MAIL_FROM
 *
 * Written by hand rather than pulled in as a dependency: this app has no
 * Composer and no vendor directory, and the subset of SMTP needed to send one
 * short message to one recipient is small enough to read in a sitting.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const FC_SMTP_TIMEOUT = 15;

/** Defaults match the Nextcloud instance; the environment overrides any of it. */
function fc_smtp_config(): array
{
    $password = fc_env('FC_SMTP_PASSWORD');
    if ($password === null) {
        // Same handling as the admin code: the `.ht` prefix is what stops
        // nginx serving it, since this host has no writable env for us.
        $raw = @file_get_contents(FC_ROOT . '/.htsmtp-password');
        $password = $raw === false ? null : (trim($raw) ?: null);
    }

    return [
        'host' => fc_env('FC_SMTP_HOST', 'smtp.gmail.com'),
        'port' => (int) fc_env('FC_SMTP_PORT', '587'),
        'secure' => strtolower((string) fc_env('FC_SMTP_SECURE', 'tls')),
        'user' => fc_env('FC_SMTP_USER', 'snoogle35@gmail.com'),
        'password' => $password,
        'from' => fc_env('FC_MAIL_FROM', 'noreply@grayflare.space'),
        'from_name' => fc_env('FC_MAIL_FROM_NAME', 'Carrier Ops'),
    ];
}

/** Whether there is enough configuration to try sending at all. */
function fc_mail_enabled(): bool
{
    $c = fc_smtp_config();
    return $c['host'] !== null && $c['from'] !== null
        && ($c['user'] === null || $c['password'] !== null);
}

// ---------------------------------------------------------------------------
// Proving an address belongs to whoever claimed it
// ---------------------------------------------------------------------------

/** Generous: people read mail hours later, and the link proves little on its own. */
const FC_VERIFY_TTL_SECONDS = 86400;

/** Sends allowed per account per hour, so the form cannot be used to post mail. */
const FC_VERIFY_MAX_PER_HOUR = 3;

/**
 * Whether an account's address can be trusted.
 *
 * Reads true when mail is not configured at all. Verification that cannot be
 * performed must not become a wall nobody can get past -- on a deployment with
 * no SMTP the honest position is that addresses are unproven, not that every
 * account is broken.
 */
function fc_email_verified(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    return ($user['email_verified_at'] ?? null) !== null || !fc_mail_enabled();
}

/**
 * Issue a fresh link and mail it.
 *
 * `$email` is the address to prove, which is not necessarily the one on the
 * account: changing an address issues a token for the new one and only writes
 * it to the account once the link is followed.
 *
 * @return bool false if it was rate limited or the send failed
 */
function fc_send_verification(int $userId, string $username, string $email): bool
{
    if (!fc_mail_enabled()) {
        return false;
    }

    $recent = (int) (fc_one(
        'SELECT COUNT(*) AS n FROM fc_email_tokens
          WHERE user_id = :id AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
        ['id' => $userId],
    )['n'] ?? 0);
    if ($recent >= FC_VERIFY_MAX_PER_HOUR) {
        return false;
    }

    // Any earlier link is spent the moment a new one is asked for, so a
    // forwarded old mail cannot still be used.
    fc_exec(
        'UPDATE fc_email_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = :id AND used_at IS NULL',
        ['id' => $userId],
    );

    $token = fc_token();
    fc_exec(
        'INSERT INTO fc_email_tokens (user_id, email, token_hash, expires_at, created_at)
         VALUES (:uid, :email, :hash, :exp, UTC_TIMESTAMP())',
        [
            'uid' => $userId,
            'email' => mb_substr($email, 0, 190),
            'hash' => hash('sha256', $token),
            'exp' => gmdate('Y-m-d H:i:s', time() + FC_VERIFY_TTL_SECONDS),
        ],
    );

    $link = fc_url('account.php?do=verify&token=' . rawurlencode($token));

    return fc_send_mail(
        $email,
        'Confirm your email for Carrier Ops',
        "Welcome to Carrier Ops, {$username}.\n\n"
        . "Confirm this address by opening the link below within 24 hours:\n\n{$link}\n\n"
        . "Until then you can sign in and look around, but you will not be able to upload journals "
        . "or claim a carrier.\n\n"
        . "If you did not create this account, ignore this message — nothing was set up in your name "
        . "beyond an unconfirmed address, and it will go no further.\n\n"
        . fc_url() . "\n",
    );
}

/**
 * Spend a verification link and mark the address proved.
 *
 * @return ?array the user row it belonged to, or null if the link is no good
 */
function fc_consume_verification(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $row = fc_one(
        'SELECT t.*, u.username FROM fc_email_tokens t
           JOIN fc_users u ON u.id = t.user_id
          WHERE t.token_hash = :hash
            AND t.used_at IS NULL
            AND t.expires_at > UTC_TIMESTAMP()
            AND u.is_banned = 0',
        ['hash' => hash('sha256', $token)],
    );
    if ($row === null) {
        return null;
    }

    // Someone else may have registered the address in the meantime; the first
    // to prove it keeps it.
    $taken = fc_one(
        'SELECT id FROM fc_users WHERE email = :e AND id <> :id',
        ['e' => $row['email'], 'id' => $row['user_id']],
    );
    if ($taken !== null) {
        fc_exec('UPDATE fc_email_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $row['id']]);
        return null;
    }

    fc_exec(
        'UPDATE fc_users SET email = :e, email_verified_at = UTC_TIMESTAMP() WHERE id = :id',
        ['e' => $row['email'], 'id' => $row['user_id']],
    );
    fc_exec(
        'UPDATE fc_email_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = :id AND used_at IS NULL',
        ['id' => $row['user_id']],
    );

    return fc_one('SELECT * FROM fc_users WHERE id = :id', ['id' => $row['user_id']]);
}

/**
 * Send one plain-text message.
 *
 * Returns false rather than throwing: a page that cannot send a password reset
 * should say so, not produce a stack trace.
 */
function fc_send_mail(string $to, string $subject, string $body): bool
{
    $config = fc_smtp_config();
    if (!fc_mail_enabled()) {
        error_log('fc: mail not configured');
        return false;
    }

    // A newline in either of these would let the caller write their own
    // headers, and the caller is ultimately holding user input.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
        error_log('fc: refusing to send a message with a malformed recipient or subject');
        return false;
    }

    $transport = $config['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $transport . $config['host'] . ':' . $config['port'],
        $errno,
        $errstr,
        FC_SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT,
    );
    if ($socket === false) {
        error_log("fc: SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($socket, FC_SMTP_TIMEOUT);

    $ok = true;
    try {
        fc_smtp_expect($socket, 220);

        $helo = parse_url(fc_base_url(), PHP_URL_HOST) ?: 'localhost';
        fc_smtp_cmd($socket, 'EHLO ' . $helo, 250);

        if ($config['secure'] === 'tls') {
            fc_smtp_cmd($socket, 'STARTTLS', 220);
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            // The server forgets everything it told us before the handshake.
            fc_smtp_cmd($socket, 'EHLO ' . $helo, 250);
        }

        if ($config['user'] !== null && $config['password'] !== null) {
            fc_smtp_cmd($socket, 'AUTH LOGIN', 334);
            fc_smtp_cmd($socket, base64_encode($config['user']), 334);
            fc_smtp_cmd($socket, base64_encode($config['password']), 235);
        }

        fc_smtp_cmd($socket, 'MAIL FROM:<' . $config['from'] . '>', 250);
        fc_smtp_cmd($socket, 'RCPT TO:<' . $to . '>', 250);
        fc_smtp_cmd($socket, 'DATA', 354);

        fwrite($socket, fc_smtp_message($config, $to, $subject, $body));
        fc_smtp_cmd($socket, '.', 250);
        fc_smtp_cmd($socket, 'QUIT', 221);
    } catch (Throwable $e) {
        // Never log the exchange itself; the AUTH lines are in it.
        error_log('fc: SMTP send failed: ' . $e->getMessage());
        $ok = false;
    }

    fclose($socket);
    return $ok;
}

function fc_smtp_cmd($socket, string $line, int $expect): void
{
    fwrite($socket, $line . "\r\n");
    fc_smtp_expect($socket, $expect);
}

/**
 * Read a reply and check its code.
 *
 * Multi-line replies mark every line but the last with a hyphen after the
 * code, so keep reading until one is not marked.
 */
function fc_smtp_expect($socket, int $expect): void
{
    $code = null;
    do {
        $line = fgets($socket, 1024);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed while waiting for ' . $expect);
        }
        $code = (int) substr($line, 0, 3);
        $more = isset($line[3]) && $line[3] === '-';
    } while ($more);

    if ($code !== $expect) {
        // The reply text is safe to log; commands are not, so callers do not
        // pass them through.
        throw new RuntimeException("SMTP expected {$expect}, got {$code}");
    }
}

/** Headers and body, dot-stuffed and CRLF terminated as SMTP requires. */
function fc_smtp_message(array $config, string $to, string $subject, string $body): string
{
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . fc_smtp_from_header($config),
        'To: <' . $to . '>',
        'Subject: ' . fc_smtp_encode_subject($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(fc_base_url(), PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ];

    $text = str_replace(["\r\n", "\r", "\n"], "\r\n", trim($body));
    // A line of a single dot would end the DATA block early.
    $text = preg_replace('/^\./m', '..', $text) ?? $text;

    return implode("\r\n", $headers) . "\r\n\r\n" . $text . "\r\n";
}

function fc_smtp_from_header(array $config): string
{
    $name = (string) $config['from_name'];
    if ($name === '') {
        return '<' . $config['from'] . '>';
    }
    return fc_smtp_encode_subject($name) . ' <' . $config['from'] . '>';
}

/** RFC 2047 for anything outside plain ASCII. */
function fc_smtp_encode_subject(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}
