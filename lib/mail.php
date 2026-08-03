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
