<?php

declare(strict_types=1);

/**
 * The admin panel, reached at settings.php?do=admin.
 *
 * Lives in lib/ and hangs off settings.php rather than being its own root
 * script, for the same reason the five auth pages became account.php: nginx
 * here has no rewrite available to this app, so every page in the docroot is
 * literally a file, and the root is kept short on purpose.
 *
 * `is_banned` has been enforced since the beginning -- on sign-in, on the API
 * key path, and on password-reset and verification links -- but until now
 * nothing could set it. This is that missing half.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/render.php';   // fc_carrier_link, fc_carrier_display_name

/**
 * Apply an admin action.
 *
 * Every branch refuses to act on the signed-in admin themselves. Banning your
 * own account or dropping your own admin bit is a locked door with the key
 * inside — recoverable only by editing the database by hand, which is exactly
 * the situation this page exists to remove.
 */
function fc_handle_admin_post(string $action, array $admin): void
{
    $targetId = (int) ($_POST['user_id'] ?? 0);
    if ($targetId === 0) {
        return;
    }

    $target = fc_one('SELECT * FROM fc_users WHERE id = :id', ['id' => $targetId]);
    if ($target === null) {
        fc_flash('No such account.', 'err');
        return;
    }

    $isSelf = (int) $target['id'] === (int) $admin['id'];

    switch ($action) {
        case 'admin_ban':
            if ($isSelf) {
                fc_flash('You cannot suspend your own account.', 'err');
                return;
            }
            fc_exec('UPDATE fc_users SET is_banned = 1 WHERE id = :id', ['id' => $targetId]);
            // A ban that leaves a live session running is not a ban. The API
            // key is checked against is_banned on every call, so it needs no
            // separate revocation.
            fc_exec('DELETE FROM fc_sessions WHERE user_id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' is suspended and was signed out everywhere.');
            return;

        case 'admin_unban':
            fc_exec('UPDATE fc_users SET is_banned = 0 WHERE id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' can sign in again.');
            return;

        case 'admin_grant':
            fc_exec('UPDATE fc_users SET is_admin = 1 WHERE id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' is now an admin.');
            return;

        case 'admin_revoke':
            if ($isSelf) {
                fc_flash('Ask another admin to remove your own admin rights.', 'err');
                return;
            }
            fc_exec('UPDATE fc_users SET is_admin = 0 WHERE id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' is no longer an admin.');
            return;

        case 'admin_unlink':
            // Removes the Frontier authorisation without touching the account.
            // Its carriers keep the customer_id that claimed them, so nothing
            // silently becomes unowned.
            fc_exec('DELETE FROM fc_capi_tokens WHERE user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_capi_pending WHERE user_id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' is no longer linked to Frontier.');
            return;

        case 'admin_delete':
            if ($isSelf) {
                fc_flash('You cannot delete your own account here.', 'err');
                return;
            }
            // Carriers are released rather than removed: the journal history
            // belongs to the carrier, not to whoever happened to claim it, and
            // another account can pick it up again by uploading.
            $released = fc_exec('UPDATE fc_carriers SET owner_user_id = NULL WHERE owner_user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_sessions WHERE user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_password_resets WHERE user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_capi_tokens WHERE user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_capi_pending WHERE user_id = :id', ['id' => $targetId]);
            fc_exec('DELETE FROM fc_users WHERE id = :id', ['id' => $targetId]);
            fc_flash($target['username'] . ' was deleted. '
                . ($released === 0 ? 'No carriers were attached.'
                    : $released . ' carrier' . ($released === 1 ? '' : 's') . ' released, with all data intact.'));
            return;
    }
}

function fc_render_admin(array $admin): void
{
    $users = fc_all(
        'SELECT u.*,
                (SELECT COUNT(*) FROM fc_carriers c WHERE c.owner_user_id = u.id) AS carriers,
                (SELECT COUNT(*) FROM fc_uploads p WHERE p.user_id = u.id) AS uploads,
                (SELECT MAX(p.ts) FROM fc_uploads p WHERE p.user_id = u.id) AS last_upload,
                (SELECT COUNT(*) FROM fc_capi_tokens k WHERE k.user_id = u.id) AS links,
                (SELECT COUNT(*) FROM fc_capi_tokens k WHERE k.user_id = u.id AND k.needs_reauth = 1) AS links_stale,
                (SELECT GROUP_CONCAT(k.customer_id ORDER BY k.id SEPARATOR ', ')
                   FROM fc_capi_tokens k WHERE k.user_id = u.id) AS customer_ids
           FROM fc_users u
          ORDER BY u.created_at DESC',
    );

    $carriers = fc_all(
        'SELECT c.*, u.username AS owner
           FROM fc_carriers c
           LEFT JOIN fc_users u ON u.id = c.owner_user_id
          ORDER BY c.updated_at DESC',
    );

    $uploads = fc_all(
        'SELECT p.*, u.username
           FROM fc_uploads p
           LEFT JOIN fc_users u ON u.id = p.user_id
          ORDER BY p.ts DESC LIMIT 25',
    );

    $admins = count(array_filter($users, static fn(array $u) => (int) $u['is_admin'] === 1));
    ?>
    <main class="wrap">
      <h1>Admin</h1>
      <?php fc_render_flash(); ?>

      <div class="stats" style="margin-bottom:20px">
        <div class="stat"><div class="k">Accounts</div><div class="v"><?= count($users) ?></div></div>
        <div class="stat"><div class="k">Admins</div><div class="v"><?= $admins ?></div></div>
        <div class="stat"><div class="k">Carriers</div><div class="v"><?= count($carriers) ?></div></div>
      </div>

      <div class="card">
        <h2>Accounts</h2>
        <div class="tablewrap">
          <table>
            <thead>
            <tr>
              <th>Account</th><th>Frontier</th><th class="num">Carriers</th>
              <th class="num">Uploads</th><th>Last seen</th><th>State</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $self = (int) $u['id'] === (int) $admin['id'];
                ?>
              <tr>
                <td>
                  <?= fc_e($u['username']) ?><?php if ($self): ?> <span class="badge accent">You</span><?php endif; ?>
                  <div class="small muted"><?= fc_e($u['cmdr_name'] ?? '—') ?> · joined <?= fc_e(fc_dt($u['created_at'])) ?></div>
                </td>
                <td class="small">
                  <?php if ((int) $u['links'] === 0): ?>
                    <span class="badge off">Not connected</span>
                  <?php else: ?>
                    <span class="mono"><?= fc_e($u['customer_ids'] ?? '') ?></span>
                    <div>
                      <?php if ((int) $u['links_stale'] > 0): ?>
                        <span class="badge warn"><?= (int) $u['links_stale'] ?> of <?= (int) $u['links'] ?> stale</span>
                      <?php else: ?>
                        <span class="badge on"><?= (int) $u['links'] ?> connected</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= (int) $u['carriers'] ?></td>
                <td class="num"><?= (int) $u['uploads'] ?></td>
                <td class="small muted nowrap">
                  <?= $u['last_upload'] !== null ? fc_e(fc_ago($u['last_upload']))
                      : ($u['last_login'] !== null ? fc_e(fc_ago($u['last_login'])) : '—') ?>
                </td>
                <td>
                  <?php if ((int) $u['is_banned'] === 1): ?><span class="badge bad">Suspended</span><?php endif; ?>
                  <?php if ((int) $u['is_admin'] === 1): ?><span class="badge accent">Admin</span><?php endif; ?>
                  <?php if ((int) $u['is_banned'] !== 1 && (int) $u['is_admin'] !== 1): ?><span class="badge">User</span><?php endif; ?>
                </td>
                <td class="right nowrap">
                  <?php if (!$self): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
                      <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">

                      <?php if ((int) $u['is_banned'] === 1): ?>
                        <button class="btn ghost sm" name="action" value="admin_unban">Restore</button>
                      <?php else: ?>
                        <button class="btn ghost sm" name="action" value="admin_ban"
                                onclick="return confirm('Suspend <?= fc_e($u['username']) ?>? They will be signed out everywhere.')">Suspend</button>
                      <?php endif; ?>

                      <?php if ((int) $u['is_admin'] === 1): ?>
                        <button class="btn ghost sm" name="action" value="admin_revoke">Drop admin</button>
                      <?php else: ?>
                        <button class="btn ghost sm" name="action" value="admin_grant"
                                onclick="return confirm('Make <?= fc_e($u['username']) ?> an admin? They will be able to see and manage every carrier.')">Make admin</button>
                      <?php endif; ?>

                      <?php if ((int) $u['links'] > 0): ?>
                        <button class="btn ghost sm" name="action" value="admin_unlink"
                                onclick="return confirm('Disconnect every Frontier account from <?= fc_e($u['username']) ?>? They will have to authorise again to upload.')">Disconnect Frontier</button>
                      <?php endif; ?>

                      <button class="btn danger ghost sm" name="action" value="admin_delete"
                              onclick="return confirm('Delete <?= fc_e($u['username']) ?>? Their carriers are released, not deleted.')">Delete</button>
                    </form>
                  <?php else: ?>
                    <span class="dim small">Use another admin</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="small dim" style="margin-bottom:0">
          Suspending ends every session and blocks the account's API key, since that is checked against the same
          flag on each call. Deleting releases any carrier it owned rather than removing it — the history belongs
          to the carrier, and another account can claim it again by uploading.
        </p>
      </div>

      <div class="card">
        <h2>Carriers</h2>
        <?php if ($carriers === []): ?>
          <div class="empty">None on the board yet.</div>
        <?php else: ?>
          <div class="tablewrap">
            <table>
              <thead><tr><th>Carrier</th><th>Owner</th><th>System</th><th>Listed</th><th>Updated</th></tr></thead>
              <tbody>
              <?php foreach ($carriers as $c): ?>
                <tr>
                  <td>
                    <a href="<?= fc_e(fc_carrier_link($c)) ?>"><?= fc_e(fc_carrier_display_name($c)) ?></a>
                    <div class="callsign small"><?= fc_e($c['callsign'] ?? '—') ?></div>
                  </td>
                  <td><?= $c['owner'] === null ? '<span class="dim">unclaimed</span>' : fc_e($c['owner']) ?></td>
                  <td class="muted"><?= fc_e($c['system'] ?? '—') ?></td>
                  <td><?= (int) $c['is_public'] === 1
                      ? '<span class="badge on">Public</span>'
                      : '<span class="badge off">Private</span>' ?></td>
                  <td class="small muted nowrap"><?= fc_e(fc_ago($c['updated_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Recent uploads</h2>
        <?php if ($uploads === []): ?>
          <div class="empty">Nothing uploaded yet.</div>
        <?php else: ?>
          <div class="tablewrap">
            <table>
              <thead>
              <tr><th>When</th><th>Account</th><th>File</th><th>Source</th>
                  <th class="num">Events</th><th class="num">Applied</th><th>Carriers</th></tr>
              </thead>
              <tbody>
              <?php foreach ($uploads as $p): ?>
                <tr>
                  <td class="small muted nowrap"><?= fc_e(fc_dt($p['ts'])) ?></td>
                  <td><?= fc_e($p['username'] ?? '—') ?></td>
                  <td class="small"><?= fc_e($p['filename'] ?? '—') ?></td>
                  <td><span class="badge"><?= fc_e($p['source']) ?></span></td>
                  <td class="num"><?= fc_num((int) $p['events_seen']) ?></td>
                  <td class="num"><?= fc_num((int) $p['events_applied']) ?></td>
                  <td class="small muted"><?= fc_e($p['carriers_touched'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="actions">
        <a class="btn ghost" href="<?= fc_e(fc_url('settings.php')) ?>">Back to settings</a>
      </div>
    </main>
    <?php
}
