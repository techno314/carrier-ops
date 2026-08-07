<?php

declare(strict_types=1);

/**
 * The admin panel's logic and rendering; admin.php is the page itself.
 *
 * Split that way so the entry point stays small enough to read in one go: it
 * checks who is asking, and everything about what the panel does lives here.
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
require_once __DIR__ . '/spool.php';
require_once __DIR__ . '/colony.php';

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
    // Maintenance acts on the site rather than on an account, so it is handled
    // before the target lookup below.
    if ($action === 'admin_maintenance_on') {
        $message = trim((string) ($_POST['message'] ?? ''));
        if (fc_maintenance_set($message === '' ? 'The board is down for maintenance. It will be back shortly.' : mb_substr($message, 0, 500))) {
            fc_flash('Maintenance mode is on. Admins are unaffected; everyone else sees a closed sign.');
        } else {
            fc_flash('Could not write the maintenance file. Check that the app directory is writable.', 'err');
        }
        return;
    }
    if ($action === 'admin_spool_test') {
        // A deliberately inert journal line: it parses, it is counted, and it
        // changes nothing. The point is to watch an upload go into the queue
        // and come out again, not to alter a carrier.
        $body = json_encode([
            'timestamp' => gmdate('c'),
            'event' => 'Music',
            'MusicTrack' => 'MainMenu',
        ], JSON_UNESCAPED_SLASHES);

        fc_flash(fc_spool_add($admin, 'test', 'test-upload.log', $body)
            ? 'Test upload queued. It will be applied on the next run, or use "Apply them now" once the board is open.'
            : 'Could not write to the spool. Check that the app directory is writable.',
            'ok');
        return;
    }

    if ($action === 'admin_spool_drain') {
        $r = fc_spool_drain();
        fc_flash($r['applied'] === 0 && $r['failed'] === 0
            ? 'Nothing was waiting.'
            : sprintf('Applied %d queued upload%s (%d events)%s.',
                $r['applied'], $r['applied'] === 1 ? '' : 's', $r['events'],
                $r['failed'] === 0 ? '' : ', ' . $r['failed'] . ' failed'),
            $r['failed'] > 0 ? 'err' : 'ok');
        return;
    }

    if ($action === 'admin_maintenance_off') {
        fc_flash(fc_maintenance_set(null)
            ? 'Maintenance mode is off. The board is open.'
            : 'Could not remove the maintenance file. Delete .htmaintenance on the server.',
            fc_maintenance() === null ? 'ok' : 'err');
        return;
    }

    // How often every planner talks to the board. Kept here rather than in the
    // clients because a colonisation crew is exactly the population that will
    // not download a new planner to change a number.
    if ($action === 'admin_colony_settings') {
        $result = fc_colony_set_settings([
            'reportSeconds' => $_POST['reportSeconds'] ?? null,
            'journalSeconds' => $_POST['journalSeconds'] ?? null,
            'carrierSeconds' => $_POST['carrierSeconds'] ?? null,
            'presentSeconds' => $_POST['presentSeconds'] ?? null,
        ]);
        $now = fc_colony_settings();
        fc_flash($result['ok']
            ? 'Saved. Every planner picks these up on its next report — reporting every '
                . $now['reportSeconds'] . 's.'
            : ($result['note'] ?? 'Nothing saved.'),
            $result['ok'] ? 'ok' : 'err');
        return;
    }

    if ($action === 'admin_colony_revoke') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $token = $id === 0 ? null : fc_one('SELECT * FROM fc_colony_tokens WHERE id = :id', ['id' => $id]);
        if ($token === null) {
            fc_flash('No such invitation.', 'err');
            return;
        }
        fc_exec('UPDATE fc_colony_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
        // Revoked rather than deleted: whoever was using it stops reporting,
        // and the row stays as a record that it existed and when it died.
        fc_flash('Invitation ' . ($token['label'] ?: 't' . $id) . ' to '
            . $token['system'] . ' will not work again.');
        return;
    }

    if ($action === 'admin_colony_revoke_all') {
        $system = trim((string) ($_POST['system'] ?? ''));
        $n = fc_exec(
            'UPDATE fc_colony_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE system = :sys AND revoked_at IS NULL',
            ['sys' => $system],
        );
        fc_flash($n === 0
            ? 'There were no live invitations to ' . $system . '.'
            : $n . ' invitation' . ($n === 1 ? '' : 's') . ' to ' . $system . ' revoked. '
                . 'Anybody using one stops reporting; account keys are unaffected.');
        return;
    }

    if ($action === 'admin_colony_delete') {
        $system = trim((string) ($_POST['system'] ?? ''));
        if ($system === '' || fc_colony_sites_in($system) === []) {
            fc_flash('No build in that system.', 'err');
            return;
        }
        $removed = fc_colony_delete_room($system);
        fc_flash(
            $system . ' cleared: ' . $removed['sites'] . ' site'
            . ($removed['sites'] === 1 ? '' : 's') . ', ' . $removed['tokens'] . ' invitation'
            . ($removed['tokens'] === 1 ? '' : 's') . '. Anyone still running a planner will '
            . 'report themselves back within seconds; the invitations will not come back.'
        );
        return;
    }

    // Carriers, like maintenance, are not accounts, so they are settled before
    // the account lookup below.
    if ($action === 'admin_carrier_delete') {
        $carrierId = (int) ($_POST['carrier_id'] ?? 0);
        $carrier = $carrierId === 0 ? null : fc_carrier($carrierId);
        if ($carrier === null) {
            fc_flash('No such carrier.', 'err');
            return;
        }

        // Typed by hand, and it has to match. A carrier is deleted from a list
        // where the rows differ by a few characters, so a misclick is the
        // likely way this ever gets used wrongly -- and a misclick cannot type
        // a callsign. Case and surrounding space are forgiven; nothing else is.
        $expected = fc_carrier_confirm_phrase($carrier);
        $typed = trim((string) ($_POST['confirm'] ?? ''));
        if (mb_strtolower($typed) !== mb_strtolower($expected)) {
            fc_flash(
                $typed === ''
                    ? 'Type ' . $expected . ' to confirm. Nothing was deleted.'
                    : 'That did not match ' . $expected . '. Nothing was deleted.',
                'err',
            );
            fc_redirect(fc_url('admin.php') . '?delete_carrier=' . $carrierId);
        }

        $name = fc_carrier_display_name($carrier);
        $removed = fc_delete_carrier($carrierId);
        $rows = (int) $removed['total'] - (int) $removed['fc_carriers'];
        fc_flash(
            $name . ' is gone, with ' . fc_num($rows) . ' row' . ($rows === 1 ? '' : 's')
            . ' of its history. '
            . ($carrier['owner_user_id'] !== null || fc_is_squadron_carrier($carrier)
                ? 'A fresh row will appear at the next sync, with current data and no past.'
                : 'Nothing connected reports it, so it will stay gone until something does.')
        );
        return;
    }

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
            // Scheduled rather than immediate. The account is suspended now, so
            // it cannot be used in the meantime; the wait exists so a mistake
            // can be undone, not so anyone keeps access.
            fc_schedule_user_deletion($targetId, true);
            fc_flash(
                $target['username'] . ' is suspended and will be erased in '
                . FC_DELETE_GRACE_DAYS . ' days. Cancel any time before then.'
            );
            return;

        case 'admin_delete_cancel':
            fc_cancel_user_deletion($targetId);
            fc_flash($target['username'] . ' will not be deleted, and can sign in again.');
            return;

        case 'admin_delete_now':
            if ($isSelf) {
                fc_flash('You cannot delete your own account here.', 'err');
                return;
            }
            $released = fc_purge_user($targetId);
            fc_flash($target['username'] . ' was erased. '
                . ($released === 0 ? 'No carriers were attached.'
                    : $released . ' carrier' . ($released === 1 ? '' : 's') . ' released, with all data intact.'));
            return;
    }
}

/**
 * What an admin must type to delete this carrier.
 *
 * The callsign, because it identifies exactly one carrier and is short enough
 * to retype without resenting it. A carrier with no callsign yet -- claimed
 * from a payload that had only an id -- falls back to whatever the board calls
 * it, so there is always something to ask for.
 */
function fc_carrier_confirm_phrase(array $carrier): string
{
    $callsign = trim((string) ($carrier['callsign'] ?? ''));
    return $callsign !== '' ? $callsign : fc_carrier_display_name($carrier);
}

/**
 * The screen that stands between a click and an irreversible deletion.
 *
 * Its own page rather than a dialog on the list. Deleting a carrier destroys
 * history that no sync will ever bring back, so it is worth the interruption of
 * leaving the table entirely, seeing what is about to go, and typing the name.
 */
function fc_render_carrier_delete_confirm(array $carrier): void
{
    $counts = fc_carrier_row_counts((int) $carrier['id']);
    $labels = fc_carrier_tables();
    $total = array_sum($counts);
    $phrase = fc_carrier_confirm_phrase($carrier);
    $owner = $carrier['owner_user_id'] === null
        ? null
        : fc_one('SELECT username FROM fc_users WHERE id = :id', ['id' => $carrier['owner_user_id']]);
    ?>
    <main class="wrap">
      <h1>Delete carrier</h1>
      <?php fc_render_flash(); ?>

      <div class="card">
        <h2><?= fc_e(fc_carrier_display_name($carrier)) ?>
          <span class="badge bad">Clears history</span>
        </h2>
        <div class="small muted" style="margin-bottom:14px">
          <span class="mono"><?= fc_e($carrier['callsign'] ?? (string) $carrier['id']) ?></span>
          · <?= $owner === null ? 'unclaimed' : 'owned by ' . fc_e($owner['username']) ?>
          · last updated <?= fc_e(fc_ago($carrier['updated_at'])) ?>
        </div>

        <?php if ($total === 0): ?>
          <div class="banner">
            Nothing is recorded against this carrier beyond the row itself.
          </div>
        <?php else: ?>
          <!-- Only the history, because whether the carrier comes back depends
               on what is connected -- the paragraph below settles that for this
               carrier rather than hedging about it here. -->
          <div class="banner warn">
            <strong><?= fc_num($total) ?> row<?= $total === 1 ? '' : 's' ?></strong> will go, and none of
            this history comes back.
          </div>
          <div class="tablewrap">
            <table>
              <thead><tr><th>What goes</th><th class="num">Rows</th></tr></thead>
              <tbody>
              <?php foreach ($counts as $table => $n): ?>
                <?php if ($n === 0) { continue; } ?>
                <tr>
                  <td><?= fc_e($labels[$table]) ?></td>
                  <td class="num"><?= fc_num($n) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <p class="small dim">
          <?php if ($carrier['owner_user_id'] !== null): ?>
            Its owner is still connected to Frontier, so a fresh row will appear at the next sync with
            current data and no past. That is the normal outcome and nothing needs doing about it:
            this clears what the board has recorded, it does not unclaim the carrier.
          <?php elseif (fc_is_squadron_carrier($carrier)): ?>
            This is a squadron carrier, so any connected member's sync will bring a fresh row back with
            current data and no past. That is the normal outcome: this clears what the board has
            recorded, it does not remove the carrier from the squadron.
          <?php else: ?>
            Nothing is connected that would report this carrier, so it will stay gone until something is.
          <?php endif; ?>
          Board posts already published to Discord are left where they are; nothing here can update them
          afterwards.
        </p>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <input type="hidden" name="carrier_id" value="<?= (int) $carrier['id'] ?>">
          <div class="field">
            <label for="confirm">Type <strong class="mono"><?= fc_e($phrase) ?></strong> to confirm</label>
            <input id="confirm" name="confirm" type="text" autocomplete="off" autocapitalize="off"
                   spellcheck="false" required placeholder="<?= fc_e($phrase) ?>">
          </div>
          <div class="actions">
            <button class="btn danger" type="submit" name="action" value="admin_carrier_delete">
              Delete <?= fc_e(fc_carrier_display_name($carrier)) ?>
            </button>
            <a class="btn ghost" href="<?= fc_e(fc_url('admin.php')) ?>">Cancel</a>
          </div>
        </form>
      </div>
    </main>
    <?php
}

function fc_render_admin(array $admin): void
{
    // The confirmation screen replaces the panel rather than sitting inside it,
    // so there is nothing else on the page to click by accident.
    $pending = (int) ($_GET['delete_carrier'] ?? 0);
    if ($pending !== 0) {
        $carrier = fc_carrier($pending);
        if ($carrier !== null) {
            fc_render_carrier_delete_confirm($carrier);
            return;
        }
        fc_flash('No such carrier.', 'err');
    }

    // GROUP_CONCAT wants a quoted separator, and this query is a single-quoted
    // PHP string, so the two would fight: an unescaped quote closes the string
    // and the rest of the SQL is silently passed as the parameter array. The
    // ids are joined in PHP instead, where the question does not arise.
    $users = fc_all(
        'SELECT u.*,
                (SELECT COUNT(*) FROM fc_carriers c WHERE c.owner_user_id = u.id) AS carriers,
                (SELECT COUNT(*) FROM fc_uploads p WHERE p.user_id = u.id) AS uploads,
                -- Deliberately not every upload row. The cron fetches Frontier
                -- for every linked account every fifteen minutes and logs each
                -- one, so a plain MAX(ts) reports when the server last polled
                -- rather than when the person was last here, which made every
                -- account show the same last-seen time a few minutes ago.
                -- The excluded source is bound rather than written inline for
                -- the same reason the ids above are joined in PHP: a quoted
                -- literal cannot appear in a single-quoted PHP string without
                -- ending it, and the rest of the SQL then becomes an argument.
                (SELECT MAX(p.ts) FROM fc_uploads p
                  WHERE p.user_id = u.id AND p.source <> :server) AS last_activity,
                (SELECT COUNT(*) FROM fc_capi_tokens k WHERE k.user_id = u.id) AS links,
                (SELECT COUNT(*) FROM fc_capi_tokens k WHERE k.user_id = u.id AND k.needs_reauth = 1) AS links_stale
           FROM fc_users u
          ORDER BY u.created_at DESC',
        ['server' => 'capi'],
    );

    foreach ($users as &$row) {
        $ids = fc_all(
            'SELECT customer_id FROM fc_capi_tokens WHERE user_id = :u ORDER BY id',
            ['u' => $row['id']],
        );
        $row['customer_ids'] = implode(', ', array_filter(array_column($ids, 'customer_id')));
    }
    unset($row);

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

      <?php $maintenance = fc_maintenance(); ?>
      <div class="card">
        <h2>Maintenance mode
          <?= $maintenance === null ? '<span class="badge on">Open</span>' : '<span class="badge bad">Closed</span>' ?>
        </h2>
        <p class="muted small">
          While it is on, everyone but an admin gets a 503 and a closed sign. The scheduled jobs keep running —
          journals already uploaded still process, and Frontier tokens still renew, so a long maintenance does not
          quietly cost anyone their link.
        </p>

        <?php if ($maintenance === null): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
            <div class="field">
              <label for="mmsg">Message <span class="dim">(optional)</span></label>
              <input id="mmsg" name="message" type="text" maxlength="500"
                     placeholder="Back in about an hour — upgrading the database.">
            </div>
            <div class="actions">
              <button class="btn danger" type="submit" name="action" value="admin_maintenance_on"
                      onclick="return confirm('Close the board to everyone except admins?')">Turn on</button>
            </div>
          </form>
        <?php else: ?>
          <div class="banner warn">
            <?= nl2br(fc_e($maintenance['message'])) ?>
            <?php if ($maintenance['since'] !== null): ?>
              <div class="small" style="margin-top:6px">On since <?= fc_e(gmdate('Y-m-d H:i', $maintenance['since'])) ?> UTC.</div>
            <?php endif; ?>
          </div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
            <div class="actions">
              <button class="btn" type="submit" name="action" value="admin_maintenance_off">Turn off</button>
            </div>
          </form>
        <?php endif; ?>

        <?php
        [$spoolFiles, $spoolBytes] = fc_spool_size();
        // Shown whenever the board is closed, even at zero: that is precisely
        // when an admin wants to know the queue is live and how much has piled
        // up. With the board open an empty queue is not news, so it stays out
        // of the way until there is something in it.
        ?>
        <?php if ($spoolFiles > 0 || $maintenance !== null): ?>
          <div class="banner<?= $spoolFiles > 0 && $maintenance === null ? ' warn' : '' ?>" style="margin-top:14px">
            <?php if ($spoolFiles === 0): ?>
              <strong>No uploads waiting.</strong>
              Anything that arrives while the board is closed is held here and applied when it reopens.
            <?php else: ?>
              <strong><?= (int) $spoolFiles ?> upload<?= $spoolFiles === 1 ? '' : 's' ?> waiting</strong>
              (<?= number_format($spoolBytes / 1024, 1) ?> KB).
              <?= $maintenance === null
                  ? 'The next scheduled run will apply them, or do it now.'
                  : 'These arrived while the board was closed and will be applied when it reopens.' ?>
            <?php endif; ?>

            <form method="post" style="margin-top:8px">
              <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
              <div class="actions" style="margin-top:0">
                <?php if ($maintenance === null && $spoolFiles > 0): ?>
                  <button class="btn ghost sm" type="submit" name="action" value="admin_spool_drain">Apply them now</button>
                <?php endif; ?>
                <button class="btn ghost sm" type="submit" name="action" value="admin_spool_test">Queue a test upload</button>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <p class="small dim" style="margin-bottom:0">
          Uploads are not refused while the board is closed — they are taken and held, then applied once it
          reopens, so nobody loses a journal to a maintenance window. Admin uploads apply immediately as usual.
          Locked out? This page stays reachable the whole time, and sends you to sign in if you are not —
          which is how an admin who was signed out when it started gets back in. The closed sign shows
          visitors no way through on purpose. Failing all that, delete <code>.htmaintenance</code> in the
          app directory: no database, no session, nothing but filesystem access.
        </p>
      </div>

      <div class="card">
        <h2>Accounts</h2>
        <div class="tablewrap">
          <table>
            <thead>
            <tr>
              <th>Account</th><th>Frontier</th><th class="num">Carriers</th>
              <th class="num">Uploads</th><th>Online</th><th>Last seen</th><th>State</th><th></th>
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
                <?php
                // Two different questions. `Online` is somebody with a page
                // open; `Last seen` is any sign of them at all, including a
                // plugin upload from a machine they are not sitting at.
                $active = (string) ($u['last_active'] ?? '');
                $seen = max($active, (string) ($u['last_activity'] ?? ''), (string) ($u['last_login'] ?? ''));
                $onlineNow = $active !== '' && time() - (int) strtotime($active . ' UTC') < 300;
                ?>
                <td class="small muted nowrap">
                  <?php if ($onlineNow): ?>
                    <span class="badge on">Now</span>
                  <?php else: ?>
                    <?= $active !== '' ? fc_e(fc_ago($active)) : '—' ?>
                  <?php endif; ?>
                </td>
                <td class="small muted nowrap">
                  <?= $seen !== '' ? fc_e(fc_ago($seen)) : '—' ?>
                </td>
                <td>
                  <?php if ($u['delete_after'] !== null): ?>
                    <?php
                    // Distinct from an ordinary suspension: both set is_banned,
                    // but only one of them ends with the account gone.
                    $left = max(0, (int) ceil(((int) strtotime((string) $u['delete_after'] . ' UTC') - time()) / 86400));
                    ?>
                    <span class="badge bad" title="Erased on <?= fc_e(fc_dt($u['delete_after'])) ?>">
                      Deleting in <?= $left ?>d
                    </span>
                  <?php elseif ((int) $u['is_banned'] === 1): ?><span class="badge bad">Suspended</span><?php endif; ?>
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

                      <?php if ($u['delete_after'] !== null): ?>
                        <button class="btn ghost sm" name="action" value="admin_delete_cancel">Keep account</button>
                        <button class="btn danger ghost sm" name="action" value="admin_delete_now"
                                onclick="return confirm('Erase <?= fc_e($u['username']) ?> now, without waiting? This cannot be undone.')">Erase now</button>
                      <?php else: ?>
                        <button class="btn danger ghost sm" name="action" value="admin_delete"
                                onclick="return confirm('Delete <?= fc_e($u['username']) ?>? They are suspended now and erased in <?= FC_DELETE_GRACE_DAYS ?> days. Their carriers are released, not deleted.')">Delete</button>
                      <?php endif; ?>
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
          to the carrier, and another account can claim it again by connecting Frontier.
        </p>
      </div>

      <div class="card">
        <h2>Carriers</h2>
        <?php if ($carriers === []): ?>
          <div class="empty">None on the board yet.</div>
        <?php else: ?>
          <div class="tablewrap">
            <table>
              <thead><tr><th>Carrier</th><th>Owner</th><th>System</th><th>Listed</th><th>Updated</th><th></th></tr></thead>
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
                  <td class="right nowrap">
                    <!-- A link, not a button: it only opens the confirmation
                         screen, and nothing is destroyed until a name has been
                         typed there. -->
                    <a class="btn danger ghost sm"
                       href="<?= fc_e(fc_url('admin.php')) ?>?delete_carrier=<?= (int) $c['id'] ?>">Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <p class="small dim" style="margin-bottom:0">
          Deleting a carrier clears its history — ledger, jumps, itinerary, market — which no sync restores.
          The carrier itself comes back at the next sync if anything is still connected to report it, which
          is what makes this a way to start a carrier's records over rather than a way to remove one.
        </p>
      </div>

      <?php
      $settings = fc_colony_settings();
      $rooms = fc_colony_rooms();
      ?>
      <div class="card">
        <h2>Colonisation</h2>
        <p class="muted small">
          A build is a whole star system, not one construction site — a colony grows several, and an
          invitation covers all of them including the ones nobody has started yet. Anybody already
          hauling in a system can invite others to it; there is no owner, because a colonisation crew
          does not have one.
        </p>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <div class="stats" style="margin-bottom:12px">
            <?php foreach ([
                'reportSeconds' => ['Report every', 'How often each planner sends its position and reads back everyone else\'s.'],
                'journalSeconds' => ['Read journals every', 'How often a planner rescans the game\'s journal on its own disk.'],
                'carrierSeconds' => ['Carrier every', 'How often a planner asks this board for a carrier\'s hold. Frontier\'s own cache is 10–15 minutes wide.'],
                'presentSeconds' => ['Counted as hauling for', 'How long after a report somebody still shows as present in the crew list.'],
            ] as $key => [$label, $hint]): ?>
              <div class="stat" title="<?= fc_e($hint) ?>">
                <div class="k"><?= fc_e($label) ?></div>
                <div class="v">
                  <input name="<?= fc_e($key) ?>" type="number" min="1" max="3600"
                         value="<?= (int) $settings[$key] ?>" style="width:5em">
                  <span class="small dim">s</span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button class="btn" type="submit" name="action" value="admin_colony_settings">Save intervals</button>
          </div>
        </form>

        <p class="small dim">
          These live on the board rather than in each planner, so a change reaches every crew member
          on their next report instead of requiring all of them to download a new copy. Values outside
          the sensible range are clamped rather than refused.
        </p>

        <?php if ($rooms === []): ?>
          <div class="empty">No colonisation builds yet. One appears when somebody's planner reports a construction site.</div>
        <?php else: ?>
          <?php foreach ($rooms as $system): $room = fc_colony_room($system); ?>
            <div class="banner" style="margin-top:14px">
              <strong><?= fc_e($system) ?></strong>
              <span class="small muted">
                · <?= count($room['sites']) ?> site<?= count($room['sites']) === 1 ? '' : 's' ?>
                · <?= count($room['haulers']) ?> hauler<?= count($room['haulers']) === 1 ? '' : 's' ?>
              </span>

              <div class="tablewrap" style="margin-top:10px">
                <table>
                  <thead><tr><th>Site</th><th class="num">Built</th><th>Last read</th></tr></thead>
                  <tbody>
                  <?php foreach ($room['sites'] as $site): ?>
                    <tr>
                      <td><?= fc_e($site['name']) ?></td>
                      <td class="num"><?= number_format(((float) $site['progress']) * 100, 1) ?>%</td>
                      <td class="small muted nowrap"><?= fc_e(fc_ago($site['read_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php if ($room['haulers'] !== []): ?>
                <div class="tablewrap" style="margin-top:10px">
                  <table>
                    <thead><tr><th>Hauler</th><th>Carrier</th><th>Ship</th><th class="num">Hold</th><th>Last reported</th></tr></thead>
                    <tbody>
                    <?php foreach ($room['haulers'] as $h): ?>
                      <?php
                      // Present is the same question the planners ask: recently
                      // enough, rather than a connection that is up or down.
                      $age = time() - (int) strtotime((string) $h['updated_at'] . ' UTC');
                      $here = $age <= $settings['presentSeconds'];
                      ?>
                      <tr>
                        <td>
                          <?= $here ? '<span class="badge on">●</span>' : '<span class="badge off">○</span>' ?>
                          <?= fc_e($h['cmdr'] ?: 'a hauler') ?>
                          <span class="small dim"><?= fc_e($h['hauler']) ?></span>
                        </td>
                        <td class="small"><?= fc_e($h['carrier'] ?? '—') ?></td>
                        <td class="small muted"><?= fc_e($h['ship'] ?? '—') ?></td>
                        <td class="num"><?= $h['cargo_capacity'] === null ? '—' : fc_num((int) $h['cargo_capacity']) . ' t' ?></td>
                        <td class="small muted nowrap"><?= fc_e(fc_ago($h['updated_at'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>

              <?php $live = array_filter($room['tokens'], static fn(array $t) => $t['revoked_at'] === null); ?>
              <div class="small muted" style="margin-top:10px">
                <?= count($live) ?> live invitation<?= count($live) === 1 ? '' : 's' ?><?php
                  if (count($room['tokens']) > count($live)) {
                      echo ', ' . (count($room['tokens']) - count($live)) . ' revoked';
                  } ?>
              </div>
              <?php if ($live !== []): ?>
                <div class="tablewrap" style="margin-top:6px">
                  <table>
                    <thead><tr><th>Invitation</th><th>Minted</th><th>Last used</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($live as $t): ?>
                      <tr>
                        <td><?= fc_e($t['label'] ?: 't' . $t['id']) ?>
                          <span class="small dim">by <?= fc_e($t['created_by']) ?></span></td>
                        <td class="small muted nowrap"><?= fc_e(fc_ago($t['created_at'])) ?></td>
                        <td class="small muted nowrap"><?= $t['last_used_at'] === null
                            ? '<span class="dim">never</span>' : fc_e(fc_ago($t['last_used_at'])) ?></td>
                        <td class="right">
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
                            <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                            <button class="btn danger ghost sm" name="action" value="admin_colony_revoke"
                                    onclick="return confirm('Revoke this invitation? Whoever is using it stops reporting.')">Revoke</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>

              <form method="post" style="margin-top:10px">
                <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
                <input type="hidden" name="system" value="<?= fc_e($system) ?>">
                <div class="actions" style="margin-top:0">
                  <?php if ($live !== []): ?>
                    <button class="btn ghost sm" name="action" value="admin_colony_revoke_all"
                            onclick="return confirm('Revoke every invitation to <?= fc_e($system) ?>? Account keys are unaffected.')">Revoke all invitations</button>
                  <?php endif; ?>
                  <button class="btn danger ghost sm" name="action" value="admin_colony_delete"
                          onclick="return confirm('Clear <?= fc_e($system) ?>? Anyone still running a planner reports themselves back within seconds; the invitations will not come back.')">Clear build</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
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
