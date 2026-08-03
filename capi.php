<?php

declare(strict_types=1);

/**
 * Linking a Carrier Ops account to Frontier, and the OAuth callback.
 *
 * This is the one page that has to be its own file. Frontier matches the
 * redirect_uri exactly against what is registered, so the address has to be
 * fixed and literal — `settings.php?do=capi` would depend on query strings
 * being allowed in a registered callback, which nothing in their documentation
 * promises. Registered as:
 *
 *     https://grayflare.space/fc/capi.php
 *
 * Frontier appends `?code=&state=` (or `?error=`) to it on the way back, which
 * is how the callback tells itself apart from someone simply opening the page.
 */

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/capi_auth.php';

// ---------------------------------------------------------------------------
// The callback
// ---------------------------------------------------------------------------

// Deliberately not behind fc_require_user(). The link is usually opened in
// whatever browser Frontier handed it back to, which may not be the one
// holding the session. The pending row already records whose authorisation
// this is, and `state` is unguessable and single-use, so that row is better
// authority here than a cookie.
if (isset($_GET['code']) || isset($_GET['error'])) {
    $error = null;
    $ok = false;

    if (isset($_GET['error'])) {
        $detail = trim((string) ($_GET['error_description'] ?? ''));
        $error = $_GET['error'] === 'access_denied'
            ? 'You cancelled the Frontier sign-in. Nothing was linked.'
            : 'Frontier refused the sign-in: ' . fc_e((string) $_GET['error'])
                . ($detail === '' ? '' : ' — ' . fc_e($detail));
    } else {
        $result = fc_capi_complete((string) $_GET['code'], (string) ($_GET['state'] ?? ''));
        $ok = $result['ok'];
        $error = $result['error'];
    }

    fc_head('Frontier account');
    ?>
    <main class="wrap narrow">
      <div class="card">
        <?php if ($ok): ?>
          <h1>Frontier account linked</h1>
          <div class="banner">
            Carrier Ops can now read your carrier from Frontier directly. You do not need the game
            or EDMC running for the board to stay current.
          </div>
          <div class="actions">
            <a class="btn" href="<?= fc_e(fc_url('capi.php')) ?>">Fetch the carrier now</a>
            <a class="btn ghost" href="<?= fc_e(fc_url()) ?>">Dashboard</a>
          </div>
        <?php else: ?>
          <h1>That did not work</h1>
          <div class="banner err"><?= $error === null ? 'The sign-in could not be completed.' : $error ?></div>
          <p class="muted small">
            Nothing was stored. If this app is still waiting on Frontier's approval, the sign-in page
            appears but the exchange afterwards is refused — that looks exactly like this and is not
            something this end can fix.
          </p>
          <div class="actions">
            <a class="btn" href="<?= fc_e(fc_url('capi.php')) ?>">Back</a>
          </div>
        <?php endif; ?>
      </div>
    </main>
    <?php
    fc_foot();
    exit;
}

// ---------------------------------------------------------------------------
// Everything else needs to be signed in
// ---------------------------------------------------------------------------

$user = fc_require_user();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'start') {
        if (!fc_capi_configured()) {
            $error = 'No Frontier client id is configured on this deployment.';
        } elseif (!isset($_POST['consent'])) {
            $error = 'Tick the box to say you understand what is read, then continue.';
        } else {
            fc_redirect(fc_capi_start((int) $user['id']));
        }
    } elseif ($action === 'disconnect') {
        fc_capi_unlink((int) $user['id']);
        fc_flash('Frontier account unlinked. The stored tokens were deleted.');
        fc_redirect(fc_url('capi.php'));
    } elseif ($action === 'sync') {
        $result = fc_capi_sync($user, true);
        if ($result['error'] !== null) {
            $error = $result['error'];
        } else {
            fc_flash($result['ok']
                ? 'Carrier updated from Frontier.'
                : ($result['note'] ?? 'Nothing new to apply.'));
            fc_redirect(fc_url('capi.php'));
        }
    }
}

$link = fc_capi_link((int) $user['id']);

fc_head('Frontier account', 'settings');
?>
<main class="wrap mid">
  <h1>Frontier account</h1>

  <?php fc_render_flash(); ?>
  <?php if ($error !== null): ?>
    <div class="banner err"><?= fc_e($error) ?></div>
  <?php endif; ?>

  <?php if (!fc_capi_configured()): ?>
    <div class="card">
      <h2>Not configured</h2>
      <p class="muted small" style="margin-bottom:0">
        This deployment has no Frontier client id. Put one in <code>.htcapi-client</code> in the app
        directory, or set <code>FC_CAPI_CLIENT_ID</code>. Frontier asks that keys stay out of source
        control, which is why it is not in the repository.
      </p>
    </div>

  <?php elseif ($link === null): ?>
    <div class="card">
      <h2>Connect to Frontier</h2>
      <p class="muted small">
        Linking lets the board read your fleet carrier from Frontier's Companion API directly, instead of
        waiting for a journal upload. It keeps cargo, the order book and the real upkeep figures current
        without the game running.
      </p>

      <h3>What is read</h3>
      <ul class="muted small">
        <li><strong>Your fleet carrier</strong> — name, position, finances, cargo, market, order book,
            crew, shipyard and outfitting stock, and its jump history.</li>
        <li><strong>An account identifier</strong> — Frontier's <code>customer_id</code> and which platform
            you play on, used to tie the link to this account.</li>
      </ul>

      <h3>What is stored</h3>
      <ul class="muted small">
        <li>Carrier data, exactly as a journal upload would store it.</li>
        <li>The <code>customer_id</code> and platform above.</li>
        <li>Access and refresh tokens, encrypted, so the board can ask again later.</li>
      </ul>

      <h3>What is not</h3>
      <ul class="muted small">
        <li>Your name and email address. Frontier returns them; they are read and discarded.</li>
        <li>Anything about other commanders, or anything outside your carrier.</li>
        <li>Your Frontier password — the sign-in happens on Frontier's own site, never here.</li>
      </ul>

      <p class="muted small">
        You can unlink at any time, which deletes the stored tokens. Frontier's authorisation expires on
        its own after a while, and the board will ask you to sign in again when it does.
      </p>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="start">
        <div class="check">
          <input type="checkbox" id="consent" name="consent">
          <label for="consent">I understand what is read and stored, and I am authorising it.</label>
        </div>
        <div class="actions">
          <button class="btn" type="submit">Continue to Frontier</button>
        </div>
      </form>
    </div>

  <?php else: ?>
    <?php $stale = (int) $link['needs_reauth'] === 1; ?>
    <div class="card">
      <h2>Linked
        <?= $stale ? '<span class="badge bad">Needs re-authorising</span>' : '<span class="badge on">Active</span>' ?>
      </h2>

      <?php if ($stale): ?>
        <div class="banner err">
          Frontier's authorisation has expired or been withdrawn, so the board can no longer read your
          carrier. Authorising again restores it.
          <?php if ($link['last_error'] !== null): ?>
            <div class="small" style="margin-top:6px">Reported: <?= fc_e($link['last_error']) ?></div>
          <?php endif; ?>
        </div>
      <?php elseif ($link['last_error'] !== null): ?>
        <div class="banner warn">Last attempt reported: <?= fc_e($link['last_error']) ?></div>
      <?php endif; ?>

      <div class="tablewrap">
        <table>
          <tbody>
          <tr><td>Platform</td><td><?= fc_e($link['platform'] ?? '—') ?></td></tr>
          <tr><td>Frontier ID</td><td class="mono"><?= fc_e($link['customer_id'] ?? '—') ?></td></tr>
          <tr><td>Scopes</td><td class="mono"><?= fc_e($link['scope'] ?? '—') ?></td></tr>
          <tr><td>Linked</td><td><?= fc_e(fc_dt($link['linked_at'])) ?></td></tr>
          <tr><td>Token renewed</td><td><?= fc_e(fc_ago($link['refreshed_at'])) ?></td></tr>
          <tr><td>Carrier last read</td><td><?= fc_e(fc_ago($link['last_fetch_at'])) ?></td></tr>
        </tbody>
        </table>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <div class="actions">
          <?php if ($stale): ?>
            <button class="btn" type="submit" name="action" value="start">Authorise again</button>
            <input type="hidden" name="consent" value="1">
          <?php else: ?>
            <button class="btn" type="submit" name="action" value="sync">Fetch the carrier now</button>
          <?php endif; ?>
          <button class="btn danger ghost" type="submit" name="action" value="disconnect"
                  onclick="return confirm('Unlink your Frontier account? The stored tokens are deleted; carrier data already on the board stays.')">Unlink</button>
        </div>
      </form>

      <p class="small dim" style="margin-bottom:0">
        The board asks Frontier at most once every <?= (int) (FC_CAPI_MIN_FETCH_INTERVAL / 60) ?> minutes;
        the button above overrides that. Tokens are stored encrypted, and unlinking deletes them.
      </p>
    </div>
  <?php endif; ?>

  <div class="actions">
    <a class="btn ghost" href="<?= fc_e(fc_url('settings.php')) ?>">Back to settings</a>
  </div>
</main>
<?php fc_foot();
