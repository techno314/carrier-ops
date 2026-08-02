<?php

declare(strict_types=1);

/**
 * Everything to do with an account: signing in and out, registering, and
 * resetting a forgotten password.
 *
 * One file rather than five because nginx here has no rewrite available to
 * this app — its fallback points at the docroot's index.php, not ours — so
 * every page is literally a file in the served directory. Five separate
 * scripts for one linear flow made the root harder to read than the code.
 */

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/mail.php';

/** Long enough to find the mail, short enough that a stolen one goes stale. */
const FC_RESET_TTL_SECONDS = 3600;

/** Requests allowed per account per hour, so the link cannot be used to spam. */
const FC_RESET_MAX_PER_HOUR = 3;

$do = (string) ($_GET['do'] ?? 'login');

match ($do) {
    'logout' => fc_page_logout(),
    'register' => fc_page_register(),
    'forgot' => fc_page_forgot(),
    'reset' => fc_page_reset(),
    default => fc_page_login(),
};

// ---------------------------------------------------------------------------

function fc_account_url(string $do, string $extra = ''): string
{
    return fc_url('account.php?do=' . $do . ($extra === '' ? '' : '&' . $extra));
}

/** Only ever redirect back inside this app, never to an attacker's URL. */
function fc_safe_next(?string $next): string
{
    if ($next === null || $next === '' || !str_starts_with($next, '/fc/') || str_starts_with($next, '//')) {
        return fc_url();
    }
    return fc_base_url() . $next;
}

function fc_redirect_if_signed_in(): void
{
    if (fc_user() !== null) {
        fc_redirect(fc_url());
    }
}

// ---------------------------------------------------------------------------

function fc_page_logout(): never
{
    fc_end_session();
    fc_redirect(fc_account_url('login'));
}

// ---------------------------------------------------------------------------

function fc_page_login(): void
{
    fc_redirect_if_signed_in();

    $next = fc_safe_next($_REQUEST['next'] ?? null);
    $error = null;
    $username = trim((string) ($_POST['username'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fc_check_csrf();
        $password = (string) ($_POST['password'] ?? '');

        $user = fc_one(
            'SELECT * FROM fc_users WHERE username = :u OR (email IS NOT NULL AND email = :e)',
            ['u' => $username, 'e' => $username],
        );

        // Hash even when there is no such user, so a wrong username and a
        // wrong password take about the same time to answer.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore.HZTJTs.iiXsyBWNRT3vFqIhr7Q0Qi';
        $ok = password_verify($password, $hash);

        if (!$ok || $user === null) {
            $error = 'That username or password is not right.';
        } elseif ((int) $user['is_banned'] === 1) {
            $error = 'That account has been suspended.';
        } else {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                fc_exec('UPDATE fc_users SET password_hash = :p WHERE id = :id', [
                    'p' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
            }
            fc_prune();
            fc_start_session((int) $user['id']);
            fc_redirect($next);
        }
    }

    fc_head('Sign in');
    ?>
    <main class="wrap narrow">
      <div class="card">
        <h1>Sign in</h1>

        <?php fc_render_flash(); ?>
        <?php if ($error !== null): ?>
          <div class="banner err"><?= fc_e($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <input type="hidden" name="next" value="<?= fc_e($_REQUEST['next'] ?? '') ?>">

          <div class="field">
            <label for="username">Username or email</label>
            <input id="username" name="username" type="text" value="<?= fc_e($username) ?>" required autofocus autocomplete="username">
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
          </div>

          <div class="actions">
            <button class="btn" type="submit">Sign in</button>
            <a class="small muted" href="<?= fc_e(fc_account_url('register')) ?>">Create an account</a>
            <a class="small muted" href="<?= fc_e(fc_account_url('forgot')) ?>">Forgotten password</a>
          </div>
        </form>
      </div>
    </main>
    <?php
    fc_foot();
}

// ---------------------------------------------------------------------------

function fc_page_register(): void
{
    fc_redirect_if_signed_in();

    $error = null;
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $cmdr = trim((string) ($_POST['cmdr'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fc_check_csrf();
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        $invite = fc_env('FC_INVITE_CODE');
        if ($invite !== null && !hash_equals($invite, (string) ($_POST['invite'] ?? ''))) {
            $error = 'That invite code is not valid.';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            $error = 'Usernames are 3–32 characters, letters, numbers, dot, dash or underscore.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } elseif (mb_strlen($password) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif (fc_one('SELECT id FROM fc_users WHERE username = :u', ['u' => $username]) !== null) {
            $error = 'That username is taken.';
        } elseif ($email !== '' && fc_one('SELECT id FROM fc_users WHERE email = :e', ['e' => $email]) !== null) {
            $error = 'That email address is already registered.';
        } else {
            // Everyone registers as an ordinary user. Admin is granted by the
            // code on the settings page — handing the role to whoever signs up
            // first would give a passer-by every carrier on the board.
            fc_exec(
                'INSERT INTO fc_users (username, email, password_hash, cmdr_name, is_admin, created_at)
                 VALUES (:u, :e, :p, :c, 0, UTC_TIMESTAMP())',
                [
                    'u' => $username,
                    'e' => $email === '' ? null : $email,
                    'p' => password_hash($password, PASSWORD_DEFAULT),
                    'c' => $cmdr === '' ? null : $cmdr,
                ],
            );
            // Read the id straight away: MySQL resets its last-insert-id on
            // every subsequent statement, so anything run in between (the
            // prune's DELETE, for one) would leave this at zero.
            $newId = (int) fc_db()->lastInsertId();

            fc_prune();
            fc_start_session($newId);
            fc_flash('Account created. Upload a journal to bring your carrier in.');
            fc_redirect(fc_url('upload.php'));
        }
    }

    fc_head('Create an account');
    ?>
    <main class="wrap narrow">
      <div class="card">
        <h1>Create an account</h1>
        <p class="muted small">Your carrier is claimed by uploading a journal that contains its events — nothing else is needed.</p>

        <?php if ($error !== null): ?>
          <div class="banner err"><?= fc_e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">

          <?php if (fc_env('FC_INVITE_CODE') !== null): ?>
            <div class="field">
              <label for="invite">Invite code</label>
              <input id="invite" name="invite" type="text" required>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= fc_e($username) ?>" required autofocus>
          </div>

          <div class="field">
            <label for="cmdr">Commander name <span class="dim">(optional)</span></label>
            <input id="cmdr" name="cmdr" type="text" value="<?= fc_e($cmdr) ?>">
          </div>

          <div class="field">
            <label for="email">Email <span class="dim">(optional, but needed to reset a forgotten password)</span></label>
            <input id="email" name="email" type="email" value="<?= fc_e($email) ?>" autocomplete="email">
          </div>

          <div class="field">
            <label for="password">Password <span class="dim">(10 characters or more)</span></label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
          </div>

          <div class="field">
            <label for="confirm">Confirm password</label>
            <input id="confirm" name="confirm" type="password" required autocomplete="new-password">
          </div>

          <div class="actions">
            <button class="btn" type="submit">Create account</button>
            <a class="small muted" href="<?= fc_e(fc_account_url('login')) ?>">I already have one</a>
          </div>
        </form>
      </div>
    </main>
    <?php
    fc_foot();
}

// ---------------------------------------------------------------------------

function fc_page_forgot(): void
{
    fc_redirect_if_signed_in();

    $sent = false;
    $error = null;
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fc_check_csrf();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter the email address on your account.';
        } elseif (!fc_mail_enabled()) {
            $error = 'Password reset is not available on this deployment.';
        } else {
            $user = fc_one('SELECT * FROM fc_users WHERE email = :e', ['e' => $email]);

            // The answer is the same either way. Saying "no such account"
            // would turn this form into a way to find out who has one.
            if ($user !== null && (int) $user['is_banned'] !== 1) {
                $recent = (int) (fc_one(
                    'SELECT COUNT(*) AS n FROM fc_password_resets
                      WHERE user_id = :id AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
                    ['id' => $user['id']],
                )['n'] ?? 0);

                if ($recent < FC_RESET_MAX_PER_HOUR) {
                    $token = fc_token();
                    fc_exec(
                        'INSERT INTO fc_password_resets (user_id, token_hash, expires_at, requested_ip, created_at)
                         VALUES (:uid, :hash, :exp, :ip, UTC_TIMESTAMP())',
                        [
                            'uid' => $user['id'],
                            'hash' => hash('sha256', $token),
                            'exp' => gmdate('Y-m-d H:i:s', time() + FC_RESET_TTL_SECONDS),
                            'ip' => @inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
                        ],
                    );

                    $link = fc_account_url('reset', 'token=' . rawurlencode($token));
                    fc_send_mail(
                        $email,
                        'Reset your Carrier Ops password',
                        "Someone asked to reset the password for {$user['username']} on Carrier Ops.\n\n"
                        . "Open this link within the hour to choose a new one:\n\n{$link}\n\n"
                        . "If that was not you, nothing has changed and you can ignore this. "
                        . "The link only works once.\n\n"
                        . fc_url() . "\n",
                    );
                }
            }

            $sent = true;
        }
    }

    fc_head('Reset your password');
    ?>
    <main class="wrap narrow">
      <div class="card">
        <h1>Reset your password</h1>

        <?php if ($sent): ?>
          <div class="banner">
            If that address is on an account, a reset link is on its way. It is good for one hour and
            can only be used once.
          </div>
          <p class="muted small">
            Nothing arrived? Check the spam folder, and make sure it is the address you registered with —
            the email field was optional, so an account may not have one.
          </p>
          <div class="actions">
            <a class="btn ghost" href="<?= fc_e(fc_account_url('login')) ?>">Back to sign in</a>
          </div>
        <?php else: ?>
          <p class="muted small">We will send a link to the address on your account.</p>

          <?php if ($error !== null): ?>
            <div class="banner err"><?= fc_e($error) ?></div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?= fc_e($email) ?>" required autofocus autocomplete="email">
            </div>
            <div class="actions">
              <button class="btn" type="submit">Send the link</button>
              <a class="small muted" href="<?= fc_e(fc_account_url('login')) ?>">Back to sign in</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </main>
    <?php
    fc_foot();
}

// ---------------------------------------------------------------------------

function fc_page_reset(): void
{
    $token = (string) ($_REQUEST['token'] ?? '');
    $error = null;
    $done = false;

    // The token is only ever stored hashed, so look it up the same way.
    $row = $token === '' ? null : fc_one(
        'SELECT r.*, u.username FROM fc_password_resets r
           JOIN fc_users u ON u.id = r.user_id
          WHERE r.token_hash = :hash
            AND r.used_at IS NULL
            AND r.expires_at > UTC_TIMESTAMP()
            AND u.is_banned = 0',
        ['hash' => hash('sha256', $token)],
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row !== null) {
        fc_check_csrf();
        $new = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (mb_strlen($new) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two passwords do not match.';
        } else {
            fc_exec('UPDATE fc_users SET password_hash = :p WHERE id = :id', [
                'p' => password_hash($new, PASSWORD_DEFAULT),
                'id' => $row['user_id'],
            ]);

            // Burn this token and any other outstanding one for the account:
            // if two were requested, the second must not still work after.
            fc_exec(
                'UPDATE fc_password_resets SET used_at = UTC_TIMESTAMP()
                  WHERE user_id = :id AND used_at IS NULL',
                ['id' => $row['user_id']],
            );

            // Whoever prompted this may not be the one holding the sessions.
            fc_exec('DELETE FROM fc_sessions WHERE user_id = :id', ['id' => $row['user_id']]);

            $done = true;
        }
    }

    fc_head('Choose a new password');
    ?>
    <main class="wrap narrow">
      <div class="card">
        <h1>Choose a new password</h1>

        <?php if ($done): ?>
          <div class="banner">
            Password changed. Every signed-in session was ended, so sign in again with the new one.
          </div>
          <div class="actions">
            <a class="btn" href="<?= fc_e(fc_account_url('login')) ?>">Sign in</a>
          </div>

        <?php elseif ($row === null): ?>
          <div class="banner err">
            That link is not valid. It may have expired, already been used, or been superseded by a
            newer request.
          </div>
          <div class="actions">
            <a class="btn" href="<?= fc_e(fc_account_url('forgot')) ?>">Send a new one</a>
          </div>

        <?php else: ?>
          <p class="muted small">Setting a new password for <strong><?= fc_e($row['username']) ?></strong>.</p>

          <?php if ($error !== null): ?>
            <div class="banner err"><?= fc_e($error) ?></div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
            <input type="hidden" name="token" value="<?= fc_e($token) ?>">

            <div class="field">
              <label for="new">New password <span class="dim">(10 characters or more)</span></label>
              <input id="new" name="new" type="password" required autofocus autocomplete="new-password">
            </div>
            <div class="field">
              <label for="confirm">Confirm password</label>
              <input id="confirm" name="confirm" type="password" required autocomplete="new-password">
            </div>

            <div class="actions">
              <button class="btn" type="submit">Change password</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </main>
    <?php
    fc_foot();
}
