<?php

declare(strict_types=1);

require __DIR__ . '/lib/core.php';

$token = (string) ($_REQUEST['token'] ?? '');
$error = null;
$done = false;

/** The token is only ever stored hashed, so look it up the same way. */
function fc_reset_row(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    return fc_one(
        'SELECT r.*, u.username FROM fc_password_resets r
           JOIN fc_users u ON u.id = r.user_id
          WHERE r.token_hash = :hash
            AND r.used_at IS NULL
            AND r.expires_at > UTC_TIMESTAMP()
            AND u.is_banned = 0',
        ['hash' => hash('sha256', $token)],
    );
}

$row = fc_reset_row($token);

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

        // Burn this token, and any other outstanding one for the account: if
        // two were requested, the second should not still work afterwards.
        fc_exec(
            'UPDATE fc_password_resets SET used_at = UTC_TIMESTAMP()
              WHERE user_id = :id AND used_at IS NULL',
            ['id' => $row['user_id']],
        );

        // Whoever prompted this may not be the one holding the old sessions.
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
        <a class="btn" href="<?= fc_e(fc_url('login.php')) ?>">Sign in</a>
      </div>

    <?php elseif ($row === null): ?>
      <div class="banner err">
        That link is not valid. It may have expired, already been used, or been superseded by a
        newer request.
      </div>
      <div class="actions">
        <a class="btn" href="<?= fc_e(fc_url('forgot.php')) ?>">Send a new one</a>
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
<?php fc_foot();
