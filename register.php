<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

if (fc_user() !== null) {
    fc_redirect(fc_url());
}

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
        // The first account to exist runs the place; everyone after is ordinary.
        $first = fc_one('SELECT id FROM fc_users LIMIT 1') === null;

        fc_exec(
            'INSERT INTO fc_users (username, email, password_hash, cmdr_name, is_admin, created_at)
             VALUES (:u, :e, :p, :c, :admin, UTC_TIMESTAMP())',
            [
                'u' => $username,
                'e' => $email === '' ? null : $email,
                'p' => password_hash($password, PASSWORD_DEFAULT),
                'c' => $cmdr === '' ? null : $cmdr,
                'admin' => $first ? 1 : 0,
            ],
        );
        // Read the id straight away: MySQL resets its last-insert-id on every
        // subsequent statement, so anything run in between (fc_prune's DELETE,
        // for one) would leave this at zero.
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
        <label for="email">Email <span class="dim">(optional — there is no password reset without it)</span></label>
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
        <a class="small muted" href="<?= fc_e(fc_url('login.php')) ?>">I already have one</a>
      </div>
    </form>
  </div>
</main>
<?php fc_foot();
