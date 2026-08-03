<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/admin.php';
require_once __DIR__ . '/lib/capi_auth.php';

$user = fc_require_user();
$error = null;
$freshKey = null;

// The admin panel is a view of this same page rather than a script of its own,
// following account.php: nginx here cannot rewrite for this app, so every page
// is a real file and the docroot is kept short deliberately.
$isAdminView = ($_GET['do'] ?? '') === 'admin';
if ($isAdminView && (int) $user['is_admin'] !== 1) {
    fc_fail(403, 'That is for admins.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if (str_starts_with($action, 'admin_')) {
        // Re-checked here and not only on the view: a POST arrives on its own
        // and must never trust that the form it came from was ever shown.
        if ((int) $user['is_admin'] !== 1) {
            fc_fail(403, 'That is for admins.');
        }
        fc_handle_admin_post($action, $user);
        fc_redirect(fc_url('settings.php?do=admin'));
    }

    if ($action === 'profile') {
        $cmdr = trim((string) ($_POST['cmdr'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } elseif ($email !== '' && fc_one('SELECT id FROM fc_users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => $user['id']]) !== null) {
            $error = 'Another account already uses that email address.';
        } else {
            fc_exec('UPDATE fc_users SET cmdr_name = :c WHERE id = :id', [
                'c' => $cmdr === '' ? null : $cmdr,
                'id' => $user['id'],
            ]);

            $current = (string) ($user['email'] ?? '');
            if ($email === $current) {
                fc_flash('Profile saved.');
            } elseif ($email === '') {
                // Removing an address is immediate. There is nothing to prove,
                // and keeping the old one against the owner's wishes would be
                // the wrong way round.
                fc_exec('UPDATE fc_users SET email = NULL, email_verified_at = NULL WHERE id = :id', ['id' => $user['id']]);
                fc_flash('Profile saved. The email address was removed, so password reset is no longer available.');
            } elseif (!fc_mail_enabled()) {
                fc_exec('UPDATE fc_users SET email = :e WHERE id = :id', ['e' => $email, 'id' => $user['id']]);
                fc_flash('Profile saved.');
            } else {
                // The new address is not written yet. It goes on the account
                // when the link is followed, so a typo cannot strand a working
                // account behind an address nobody reads.
                $ok = fc_send_verification((int) $user['id'], (string) $user['username'], $email);
                fc_flash($ok
                    ? 'Profile saved. Confirm ' . $email . ' with the link we just sent — until then the old address stays on the account.'
                    : 'Profile saved, but no confirmation mail went out. Try again in a while.',
                    $ok ? 'ok' : 'err');
            }
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

if ($isAdminView) {
    fc_head('Admin', 'settings');
    fc_render_admin($user);
    fc_foot();
    exit;
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
        <?php if (fc_mail_enabled()): ?>
          <p class="small" style="margin:8px 0 0">
            <?php if (($user['email'] ?? null) === null): ?>
              <span class="badge off">No address</span>
              <span class="dim">Without one there is no way to reset a forgotten password.</span>
            <?php elseif ($user['email_verified_at'] !== null): ?>
              <span class="badge on">Confirmed</span>
              <span class="dim">Changing it sends a new link, and the old address stays until that link is followed.</span>
            <?php else: ?>
              <span class="badge warn">Not confirmed</span>
              <span class="dim">Uploading is closed until it is.</span>
              <a href="<?= fc_e(fc_url('account.php?do=resend')) ?>">Send the link again</a>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>

      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </div>

  <?php $capiLink = fc_capi_link((int) $user['id']); ?>
  <div class="card">
    <h2>Frontier account
      <?php if (!fc_capi_configured()): ?>
        <span class="badge off">Unavailable</span>
      <?php elseif ($capiLink === null): ?>
        <span class="badge off">Not linked</span>
      <?php elseif ((int) $capiLink['needs_reauth'] === 1): ?>
        <span class="badge bad">Needs re-authorising</span>
      <?php else: ?>
        <span class="badge on">Linked</span>
      <?php endif; ?>
    </h2>
    <p class="muted small">
      Linking to Frontier lets the board read your carrier directly, including the cargo hold and the real
      upkeep figures, without the game or EDMC running. Journal uploads keep working either way.
    </p>
    <?php if (fc_capi_configured()): ?>
      <div class="actions">
        <a class="btn<?= $capiLink === null ? '' : ' ghost' ?>" href="<?= fc_e(fc_url('capi.php')) ?>">
          <?= $capiLink === null ? 'Connect to Frontier' : 'Manage the link' ?>
        </a>
      </div>
    <?php else: ?>
      <p class="small dim" style="margin-bottom:0">No Frontier client id is configured on this deployment.</p>
    <?php endif; ?>
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
      <p class="muted small">
        This account is an admin. It can see and manage every carrier on the board, suspend accounts,
        and hand the role to somebody else.
      </p>
      <div class="actions">
        <a class="btn" href="<?= fc_e(fc_url('settings.php?do=admin')) ?>">Open the admin panel</a>
      </div>
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
