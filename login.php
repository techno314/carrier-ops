<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

if (fc_user() !== null) {
    fc_redirect(fc_url());
}

/** Only ever redirect back inside this app, never to an attacker's URL. */
function fc_safe_next(?string $next): string
{
    if ($next === null || $next === '' || !str_starts_with($next, '/fc/') || str_starts_with($next, '//')) {
        return fc_url();
    }
    return fc_base_url() . $next;
}

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

    // Hash even when there is no such user, so a wrong username and a wrong
    // password take about the same time to answer.
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
        <a class="small muted" href="<?= fc_e(fc_url('register.php')) ?>">Create an account</a>
      </div>
    </form>
  </div>
</main>
<?php fc_foot();
