<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_render.php';

$user = fc_require_user();
$error = null;
$freshKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $cmdr = trim((string) ($_POST['cmdr'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } elseif ($email !== '' && fc_one('SELECT id FROM fc_users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => $user['id']]) !== null) {
            $error = 'Another account already uses that email address.';
        } else {
            fc_exec('UPDATE fc_users SET cmdr_name = :c, email = :e WHERE id = :id', [
                'c' => $cmdr === '' ? null : $cmdr,
                'e' => $email === '' ? null : $email,
                'id' => $user['id'],
            ]);
            fc_flash('Profile saved.');
            fc_redirect(fc_url('settings.php'));
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = 'That is not your current password.';
        } elseif (mb_strlen($new) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two new passwords do not match.';
        } else {
            fc_exec('UPDATE fc_users SET password_hash = :p WHERE id = :id', [
                'p' => password_hash($new, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
            // Every other session is now stale — signing out everywhere is the
            // point of changing a password.
            $raw = $_COOKIE[FC_SESSION_COOKIE] ?? '';
            fc_exec('DELETE FROM fc_sessions WHERE user_id = :id AND token_hash <> :keep', [
                'id' => $user['id'],
                'keep' => hash('sha256', $raw),
            ]);
            fc_flash('Password changed. Other sessions were signed out.');
            fc_redirect(fc_url('settings.php'));
        }
    } elseif ($action === 'newkey') {
        // Shown once and stored hashed, so a leak of the table does not hand
        // out working keys.
        $freshKey = fc_token();
        fc_exec('UPDATE fc_users SET api_key_hash = :h WHERE id = :id', [
            'h' => hash('sha256', $freshKey),
            'id' => $user['id'],
        ]);
        $user = fc_one('SELECT * FROM fc_users WHERE id = :id', ['id' => $user['id']]) ?? $user;
    } elseif ($action === 'promote') {
        $code = fc_admin_code();
        $sent = (string) ($_POST['admin_code'] ?? '');
        if ($code === null) {
            $error = 'Admin promotion is closed on this deployment.';
        } elseif (!hash_equals($code, $sent)) {
            $error = 'That admin code is not right.';
        } else {
            fc_exec('UPDATE fc_users SET is_admin = 1 WHERE id = :id', ['id' => $user['id']]);
            fc_flash('You are now an admin.');
            fc_redirect(fc_url('settings.php'));
        }
    } elseif ($action === 'revokekey') {
        fc_exec('UPDATE fc_users SET api_key_hash = NULL WHERE id = :id', ['id' => $user['id']]);
        fc_flash('API key revoked.');
        fc_redirect(fc_url('settings.php'));
    }
}

$carriers = fc_all('SELECT * FROM fc_carriers WHERE owner_user_id = :uid ORDER BY updated_at DESC', ['uid' => $user['id']]);

fc_head('Settings', 'settings');
?>
<main class="wrap mid">
  <h1>Settings</h1>

  <?php fc_render_flash(); ?>
  <?php if ($error !== null): ?>
    <div class="banner err"><?= fc_e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Profile</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
      <input type="hidden" name="action" value="profile">

      <div class="field">
        <label>Username</label>
        <input type="text" value="<?= fc_e($user['username']) ?>" disabled>
      </div>
      <div class="field">
        <label for="cmdr">Commander name</label>
        <input id="cmdr" name="cmdr" type="text" value="<?= fc_e($user['cmdr_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= fc_e($user['email'] ?? '') ?>">
      </div>

      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </div>

  <div class="card">
    <h2>API key</h2>
    <?php if ($freshKey !== null): ?>
      <div class="banner">
        Here is your key. It is not shown again — store it now.
        <div class="keybox" style="margin-top:10px"><?= fc_e($freshKey) ?></div>
      </div>
    <?php endif; ?>

    <p class="muted small">
      A key lets a script post journal data without signing in. It can upload and read your own carriers, nothing else.
      <?= $user['api_key_hash'] === null ? 'You do not have one.' : 'A key is active.' ?>
      The <a href="<?= fc_e(fc_url('plugin.php')) ?>">EDMC plugin</a> uses one to keep the board current by itself.
    </p>

    <h3>Posting data</h3>
    <pre class="code">curl -X POST <?= fc_e(fc_url('api.php?action=ingest')) ?> \
  -H "X-API-Key: YOUR_KEY" \
  --data-binary @Journal.2026-08-02T120000.01.log</pre>

    <h3>Reading it back</h3>
    <pre class="code">curl -H "X-API-Key: YOUR_KEY" "<?= fc_e(fc_url('api.php?action=carrier&id=CALLSIGN')) ?>"</pre>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
      <div class="actions">
        <button class="btn" type="submit" name="action" value="newkey">
          <?= $user['api_key_hash'] === null ? 'Create a key' : 'Replace the key' ?>
        </button>
        <?php if ($user['api_key_hash'] !== null): ?>
          <button class="btn ghost" type="submit" name="action" value="revokekey">Revoke</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Your carriers</h2>
    <?php if ($carriers === []): ?>
      <div class="empty">None claimed yet. <a href="<?= fc_e(fc_url('upload.php')) ?>">Upload a journal</a> to claim one.</div>
    <?php else: ?>
      <div class="tablewrap">
        <table>
          <thead><tr><th>Carrier</th><th>System</th><th>Listed</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($carriers as $carrier): ?>
            <tr>
              <td>
                <a href="<?= fc_e(fc_carrier_link($carrier)) ?>"><?= fc_e(fc_carrier_display_name($carrier)) ?></a>
                <div class="callsign small"><?= fc_e($carrier['callsign'] ?? '—') ?></div>
              </td>
              <td class="muted"><?= fc_e($carrier['system'] ?? '—') ?></td>
              <td><?= (int) $carrier['is_public'] === 1 ? '<span class="badge on">Public</span>' : '<span class="badge off">Private</span>' ?></td>
              <td class="right"><a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=manage">Manage</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ((int) $user['is_admin'] !== 1 && fc_admin_code() !== null): ?>
    <div class="card">
      <h2>Admin</h2>
      <p class="muted small">
        The code is in <code>.htadmin-code</code> in the app directory on the server. Admins can view and take over
        any carrier on the board, so it is deliberately not something you can claim just by signing up.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="promote">
        <div class="field">
          <label for="admin_code">Admin code</label>
          <input id="admin_code" name="admin_code" type="password" autocomplete="off">
        </div>
        <div class="actions"><button class="btn ghost" type="submit">Become an admin</button></div>
      </form>
    </div>
  <?php elseif ((int) $user['is_admin'] === 1): ?>
    <div class="card">
      <h2>Admin</h2>
      <p class="muted small" style="margin-bottom:0">This account is an admin. It can see and manage every carrier on the board.</p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Password</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
      <input type="hidden" name="action" value="password">

      <div class="field">
        <label for="current">Current password</label>
        <input id="current" name="current" type="password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label for="new">New password</label>
        <input id="new" name="new" type="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="confirm">Confirm new password</label>
        <input id="confirm" name="confirm" type="password" required autocomplete="new-password">
      </div>

      <div class="actions"><button class="btn" type="submit">Change password</button></div>
    </form>
  </div>
</main>
<?php fc_foot();
