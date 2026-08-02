<?php

declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/mail.php';

if (fc_user() !== null) {
    fc_redirect(fc_url());
}

/** Long enough to find the mail, short enough that a stolen one goes stale. */
const FC_RESET_TTL_SECONDS = 3600;

/** Requests allowed per account per hour, so the link cannot be used to spam. */
const FC_RESET_MAX_PER_HOUR = 3;

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

        // The answer is the same either way. Saying "no such account" would
        // turn this form into a way to find out who has one.
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

                $link = fc_url('reset.php?token=' . rawurlencode($token));
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
        <a class="btn ghost" href="<?= fc_e(fc_url('login.php')) ?>">Back to sign in</a>
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
          <a class="small muted" href="<?= fc_e(fc_url('login.php')) ?>">Back to sign in</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php fc_foot();
