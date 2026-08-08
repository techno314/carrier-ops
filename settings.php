<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/capi_auth.php';
require_once __DIR__ . '/lib/colony.php';

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
    } elseif ($action === 'delete_account') {
        // Self-service, so asking to be erased does not depend on an admin
        // noticing a message somewhere. The password is required because this
        // ends the account, and a borrowed session should not be able to.
        if (!password_verify((string) ($_POST['current'] ?? ''), (string) $user['password_hash'])) {
            $error = 'That is not your password.';
        } else {
            // Not suspended: the wait is yours to change your mind in, and
            // locking the account would take away the cancel button with it.
            fc_schedule_user_deletion((int) $user['id'], false);
            fc_flash(
                'Your account will be deleted in ' . FC_DELETE_GRACE_DAYS
                . ' days. You can change your mind here until then.'
            );
            fc_redirect(fc_url('settings.php'));
        }
    } elseif ($action === 'delete_account_cancel') {
        fc_cancel_user_deletion((int) $user['id']);
        fc_flash('Your account will not be deleted.');
        fc_redirect(fc_url('settings.php'));
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
    } elseif ($action === 'bind_squadron') {
        // Frontier reports a squadron carrier with no id of any kind, so the
        // only way to know which row it is is to be told. Restricted to the
        // squadron's owner, and to rows nobody else has a claim on.
        $linkId = (int) ($_POST['link'] ?? 0);
        $carrierId = (int) ($_POST['carrier'] ?? 0);

        $member = fc_one(
            'SELECT * FROM fc_squadron_members WHERE link_id = :l AND user_id = :u',
            ['l' => $linkId, 'u' => $user['id']],
        );
        $row = $carrierId > 0 ? fc_carrier($carrierId) : null;

        if ($member === null) {
            $error = 'That Frontier link is not on this account.';
        } elseif ($member['owner_cmdr_id'] === null
            || (int) $member['owner_cmdr_id'] !== (int) ($member['cmdr_id'] ?? 0)) {
            $error = 'Only the squadron\'s owner can identify its carrier.';
        } elseif ($row === null) {
            $error = 'No carrier with that id.';
        } elseif ($row['owner_user_id'] !== null || $row['squadron_id'] !== null) {
            $error = 'That carrier already belongs to someone.';
        } else {
            fc_update_carrier($carrierId, [
                'squadron_id' => (int) $member['squadron_id'],
                'squadron_name' => $member['squadron_name'],
                'squadron_tag' => $member['squadron_tag'],
            ]);
            // Now that it is bound, an ordinary sync fills it in: fc_squadron_bind
            // finds the row by its squadron id and everything else follows.
            $sync = fc_capi_sync($user, $linkId, true);
            fc_flash($sync['error'] === null
                ? 'Squadron carrier identified. Its details will fill in from Frontier.'
                : 'Squadron carrier identified, but the fetch that follows failed: ' . $sync['error']);
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

    } elseif ($action === 'revoke_invite') {
        // Scoped to this account's own invitations by the WHERE clause rather
        // than by checking first and updating after: one statement cannot be
        // raced, and an id belonging to somebody else simply matches nothing.
        $id = (int) ($_POST['token_id'] ?? 0);
        $done = fc_exec(
            'UPDATE fc_colony_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE id = :id AND created_by = :me AND revoked_at IS NULL',
            ['id' => $id, 'me' => 'u' . $user['id']],
        );
        fc_flash($done > 0
            ? 'Invitation revoked. Whoever was using it stops reporting; nothing they already contributed is removed.'
            : 'That invitation is not yours, or was already revoked.',
            $done > 0 ? 'ok' : 'err');
        fc_redirect(fc_url('settings.php'));

    } elseif ($action === 'revoke_invites_all') {
        $done = fc_exec(
            'UPDATE fc_colony_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE created_by = :me AND revoked_at IS NULL',
            ['me' => 'u' . $user['id']],
        );
        fc_flash($done === 0
            ? 'You had no live invitations.'
            : $done . ' invitation' . ($done === 1 ? '' : 's') . ' revoked.');
        fc_redirect(fc_url('settings.php'));
    }
}

// Invitations this account has handed out. Revoked ones are kept and shown, so
// that "did I already turn that off?" has an answer.
$invites = fc_all(
    'SELECT t.*, s.name AS site_name
       FROM fc_colony_tokens t
       LEFT JOIN fc_colony_sites s ON s.market_id = t.market_id
      WHERE t.created_by = :me
      ORDER BY t.revoked_at IS NOT NULL, t.id DESC',
    ['me' => 'u' . $user['id']],
);

$carriers = fc_all('SELECT * FROM fc_carriers WHERE owner_user_id = :uid ORDER BY updated_at DESC', ['uid' => $user['id']]);

fc_head('Settings', 'settings');
?>
<main class="wrap mid">
  <h1>Settings</h1>

  <?php fc_render_flash(); ?>
  <?php if ($error !== null): ?>
    <div class="banner err"><?= fc_e($error) ?></div>
  <?php endif; ?>

  <details class="card" open>
    <summary><h2>Profile</h2></summary>
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
  </details>

  <?php $capiLinks = fc_capi_links((int) $user['id']);
        $capiStale = count(array_filter($capiLinks, static fn(array $l) => (int) $l['needs_reauth'] === 1)); ?>
  <details class="card">
    <summary><h2>Frontier account
      <?php if (!fc_capi_configured()): ?>
        <span class="badge off">Unavailable</span>
      <?php elseif ($capiLinks === []): ?>
        <span class="badge off">Not connected</span>
      <?php elseif ($capiStale > 0): ?>
        <span class="badge bad"><?= $capiStale ?> need<?= $capiStale === 1 ? 's' : '' ?> re-authorising</span>
      <?php else: ?>
        <span class="badge on"><?= count($capiLinks) ?> connected</span>
      <?php endif; ?>
    </h2></summary>
    <p class="muted small">
      Connecting to Frontier lets the board read your carrier directly, including the cargo hold and the real
      upkeep figures, without the game or EDMC running. Elite allows one carrier per Frontier account, so
      connect several to watch several. Journal uploads keep working either way.
    </p>
    <?php if (fc_capi_configured()): ?>
      <div class="actions">
        <a class="btn<?= $capiLinks === [] ? '' : ' ghost' ?>" href="<?= fc_e(fc_url('capi.php')) ?>">
          <?= $capiLinks === [] ? 'Connect to Frontier' : 'Manage connected accounts' ?>
        </a>
      </div>
    <?php else: ?>
      <p class="small dim" style="margin-bottom:0">No Frontier client id is configured on this deployment.</p>
    <?php endif; ?>
  </details>

  <?php
  $squadrons = fc_all(
      'SELECT * FROM fc_squadron_members WHERE user_id = :u ORDER BY squadron_name',
      ['u' => $user['id']],
  );
  ?>
  <?php if ($squadrons !== []): ?>
    <details class="card">
      <summary><h2>Squadrons</h2></summary>
      <p class="muted small">
        Read from Frontier alongside your carrier. A squadron carrier belongs to the squadron rather than to
        anyone in it, so it shows on your dashboard for as long as you are a member — no claiming involved.
      </p>

      <div class="tablewrap">
        <table>
          <thead><tr><th>Squadron</th><th>Rank</th><th>Carrier</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($squadrons as $squadron): ?>
            <?php
            $bound = fc_one(
                'SELECT * FROM fc_carriers WHERE squadron_id = :sq LIMIT 1',
                ['sq' => $squadron['squadron_id']],
            );
            $isOwner = $squadron['owner_cmdr_id'] !== null
                && (int) $squadron['owner_cmdr_id'] === (int) ($squadron['cmdr_id'] ?? 0);
            ?>
            <tr>
              <td>
                <?= fc_e($squadron['squadron_name'] ?? '—') ?>
                <div class="callsign small"><?= fc_e($squadron['squadron_tag'] ?? '') ?></div>
              </td>
              <td>
                <?= fc_e($squadron['rank_name'] ?? '—') ?>
                <?php if ($isOwner): ?><span class="badge on">Owner</span><?php endif; ?>
              </td>
              <td>
                <?php if ($bound !== null): ?>
                  <a href="<?= fc_e(fc_carrier_link($bound)) ?>">
                    <?= fc_e(fc_carrier_display_name($bound)) ?>
                  </a>
                <?php elseif ($squadron['pending_carrier'] !== null): ?>
                  <span class="badge warn">Not identified</span>
                  <div class="dim small">callsign <?= fc_e((string) $squadron['pending_carrier']) ?></div>
                <?php else: ?>
                  <span class="muted small">None</span>
                <?php endif; ?>
              </td>
              <td class="right"></td>
            </tr>

            <?php if ($bound === null && $squadron['pending_carrier'] !== null && $isOwner): ?>
              <?php
              // Candidates are carriers nobody has claimed, which is what a
              // squadron carrier looks like when it arrives from a journal:
              // CarrierLocation gives its id and its position and no name at
              // all, so an unnamed row is the likeliest match by far.
              $candidates = fc_all(
                  "SELECT * FROM fc_carriers
                    WHERE owner_user_id IS NULL AND squadron_id IS NULL
                      AND (callsign IS NULL OR callsign = :cs)
                    ORDER BY (callsign = :cs) DESC, updated_at DESC
                    LIMIT 10",
                  ['cs' => $squadron['pending_carrier']],
              );
              ?>
              <tr>
                <td colspan="4">
                  <?php if ($candidates === []): ?>
                    <p class="muted small" style="margin:0">
                      Frontier says this squadron has a carrier but gives it no id, and nothing on the board
                      matches it yet. Upload a journal from a session where you were aboard or alongside it —
                      <code>CarrierLocation</code> carries the id we need.
                    </p>
                  <?php else: ?>
                    <p class="muted small">
                      Frontier gives a squadron carrier no id, so it has to be matched by hand. These are the
                      carriers on the board that nobody has claimed:
                    </p>
                    <?php foreach ($candidates as $candidate): ?>
                      <form method="post" style="display:inline-block;margin:0 8px 8px 0">
                        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
                        <input type="hidden" name="action" value="bind_squadron">
                        <input type="hidden" name="link" value="<?= (int) $squadron['link_id'] ?>">
                        <input type="hidden" name="carrier" value="<?= fc_e((string) $candidate['id']) ?>">
                        <button class="btn ghost sm" type="submit">
                          <?= fc_e($candidate['callsign'] ?? ('Carrier ' . $candidate['id'])) ?>
                          <span class="dim">· <?= fc_e($candidate['system'] ?? 'position unknown') ?></span>
                        </button>
                      </form>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <details class="card">
    <summary><h2>API key</h2></summary>
    <?php if ($freshKey !== null): ?>
      <div class="banner">
        Here is your key. It is not shown again — store it now.
        <div class="keybox" style="margin-top:10px"><?= fc_e($freshKey) ?></div>
      </div>
    <?php endif; ?>

    <p class="muted small">
      A key lets a program act as you without signing in.
      <?= $user['api_key_hash'] === null ? 'You do not have one.' : 'A key is active.' ?>
      The <a href="<?= fc_e(fc_url('plugin.php')) ?>">EDMC plugin</a> uses one to keep the board current by itself,
      and the <a href="<?= fc_e(fc_url('planner.php')) ?>">Colony Planner</a> uses one to read your carrier's hold
      and to share a build with the people hauling to it.
    </p>

    <p class="muted small">
      It can upload journals, read the carriers you own, and take part in colonisation builds — which includes
      <strong>creating invitations</strong> that let other people join a build without an account here. Treat it as
      you would a password: anyone holding it can do those things as you, and revoking is the only way to stop them.
    </p>

    <p class="small dim">
      It cannot read another account's carriers, change your password, or sign in to this site.
      An invitation it creates is limited to one star system's build and expires only when revoked —
      see <a href="<?= fc_e(fc_url('planner.php')) ?>">the planner page</a>.
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
  </details>

  <?php $liveInvites = array_filter($invites, static fn(array $t) => $t['revoked_at'] === null); ?>
  <details class="card"<?= $liveInvites === [] ? '' : ' open' ?>>
    <summary><h2>Build invitations<?= $liveInvites === [] ? '' : ' <span class="badge">' . count($liveInvites) . ' live</span>' ?></h2></summary>

    <p class="muted small">
      Invitations you have handed out from the <a href="<?= fc_e(fc_url('planner.php')) ?>">Colony Planner</a>.
      Each one lets somebody take part in one star system's build without an account here — nothing else on this
      board — and it works until you revoke it.
    </p>

    <?php if ($invites === []): ?>
      <div class="empty">You have not created any.</div>
    <?php else: ?>
      <div class="tablewrap">
        <table>
          <thead><tr><th>Invitation</th><th>Build</th><th>Created</th><th>Last used</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invites as $t): $dead = $t['revoked_at'] !== null; ?>
            <tr<?= $dead ? ' class="dim"' : '' ?>>
              <td><?= fc_e($t['label'] ?: 'unlabelled') ?></td>
              <td class="small"><?= fc_e($t['site_name'] ?? $t['system']) ?>
                <?php if ($t['site_name'] !== null): ?>
                  <div class="small dim"><?= fc_e($t['system']) ?></div>
                <?php endif; ?></td>
              <td class="small muted nowrap"><?= fc_e(fc_ago($t['created_at'])) ?></td>
              <td class="small muted nowrap">
                <?php if ($dead): ?>
                  <span class="badge off">revoked</span>
                <?php elseif ($t['last_used_at'] === null): ?>
                  <span class="dim">never</span>
                <?php else: ?>
                  <?= fc_e(fc_ago($t['last_used_at'])) ?>
                <?php endif; ?>
              </td>
              <td class="right">
                <?php if (!$dead): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
                    <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                    <button class="btn danger ghost sm" name="action" value="revoke_invite"
                            onclick="return confirm('Revoke this invitation? Whoever is using it stops reporting.')">Revoke</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($liveInvites !== []): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <div class="actions">
            <button class="btn ghost sm" name="action" value="revoke_invites_all"
                    onclick="return confirm('Revoke every invitation you have created?')">Revoke all</button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($invites !== []): ?>
      <p class="small dim" style="margin-bottom:0">
        Revoking stops somebody reporting from that moment. What they already contributed stays on the build —
        it is theirs, and removing it would leave the crew's totals wrong.
      </p>
    <?php endif; ?>
  </details>

  <details class="card">
    <summary><h2>Your carriers</h2></summary>
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
  </details>

  <?php if ((int) $user['is_admin'] !== 1 && fc_admin_code() !== null): ?>
    <details class="card">
      <summary><h2>Admin</h2></summary>
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
    </details>
  <?php elseif ((int) $user['is_admin'] === 1): ?>
    <details class="card">
      <summary><h2>Admin</h2></summary>
      <p class="muted small">
        This account is an admin. It can see and manage every carrier on the board, suspend accounts,
        and hand the role to somebody else.
      </p>
      <div class="actions">
        <a class="btn" href="<?= fc_e(fc_url('admin.php')) ?>">Open the admin panel</a>
      </div>
    </details>
  <?php endif; ?>

  <details class="card">
    <summary><h2>Password</h2></summary>
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
  </details>

  <details class="card">
    <summary><h2>Delete your account</h2></summary>

    <?php if ($user['delete_after'] !== null): ?>
      <div class="banner warn">
        Your account is scheduled for deletion on
        <strong><?= fc_e(fc_dt($user['delete_after'])) ?> UTC</strong>.
        Nothing has been removed yet and the board works as usual until then.
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="delete_account_cancel">
        <div class="actions"><button class="btn" type="submit">Keep my account</button></div>
      </form>
    <?php else: ?>
      <p class="muted small">
        Deletion is scheduled <?= FC_DELETE_GRACE_DAYS ?> days ahead so it can be undone — sign in before
        then and press the button that appears here. After that it is permanent.
      </p>
      <p class="muted small">
        Erased: your sign-in details and email, your Frontier authorisations, your upload history, your
        webhooks, and your squadron membership. Your carriers are <em>released</em> rather than deleted —
        their journal history belongs to the carrier, and it can be claimed again by connecting the Frontier
        account that owns it.
        Nothing identifying you is left on them.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="delete_account">
        <div class="field">
          <label for="delcurrent">Confirm with your password</label>
          <input id="delcurrent" name="current" type="password" required autocomplete="current-password">
        </div>
        <div class="actions">
          <button class="btn danger" type="submit"
                  onclick="return confirm('Schedule your account for deletion in <?= FC_DELETE_GRACE_DAYS ?> days?')">
            Delete my account
          </button>
        </div>
      </form>
    <?php endif; ?>
  </details>
</main>
<?php fc_foot();
