<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';

$user = fc_user();

if ($user === null) {
    fc_head('Fleet carrier management');
    ?>
    <main class="wrap mid">
      <?php fc_render_flash(); ?>
      <div class="hero">
        <img src="/fc/assets/carrier-banner.jpg?v=<?= fc_e(fc_asset_version('carrier-banner.jpg')) ?>"
             alt="A Drake-Class fleet carrier against the galactic plane" width="1200" height="630">
      </div>
      <div class="card">
        <h1>Carrier Ops</h1>
        <p class="muted">
          A management board for Elite Dangerous fleet carriers: finances and upkeep, the commodity market,
          jump schedule, crew, shipyard and outfitting stock — all read from the journal files the game already
          writes on your own machine.
        </p>
        <p class="muted">
          You sign in to Frontier once, which is what proves the carrier is yours, and the board reads it from
          there. Journal uploads still work alongside it and fill in what the Companion API does not report —
          on your own machine, from files the game already writes.
        </p>
        <div class="actions">
          <a class="btn" href="<?= fc_e(fc_url('account.php?do=register')) ?>">Create an account</a>
          <a class="btn ghost" href="<?= fc_e(fc_url('account.php?do=login')) ?>">Sign in</a>
          <a class="btn ghost" href="<?= fc_e(fc_url('search.php')) ?>">Browse carriers</a>
        </div>
      </div>

      <div class="card">
        <h2>What gets read</h2>
        <p class="muted small">
          Only fleet carrier events are taken from an uploaded journal — <code>CarrierStats</code>,
          <code>CarrierJump</code>, <code>CarrierTradeOrder</code> and their siblings, plus the
          <code>Market.json</code>, <code>Shipyard.json</code> and <code>Outfitting.json</code> snapshots if you
          include them. Everything else in the file is ignored and never stored: where you have been, what you
          scanned, who you fought, what was said in chat.
        </p>
      </div>
    </main>
    <?php
    fc_foot();
    exit;
}

$mine = fc_all(
    'SELECT * FROM fc_carriers WHERE owner_user_id = :uid ORDER BY updated_at DESC',
    ['uid' => $user['id']],
);

fc_head('Dashboard');
?>
<main class="wrap">
  <?php fc_render_flash(); ?>

  <?php if ($mine === []): ?>
    <div class="card">
      <h1>No carrier yet</h1>
      <?php if (fc_capi_configured() && !fc_account_linked($user)): ?>
        <p class="muted">
          Connect your Frontier account and your carrier appears here by itself. That sign-in is also what claims
          it — a journal cannot prove a carrier is yours, since it is a file anyone could write.
        </p>
        <div class="actions">
          <a class="btn" href="<?= fc_e(fc_url('capi.php')) ?>">Connect to Frontier</a>
        </div>
      <?php else: ?>
        <p class="muted">
          Nothing has come through yet. If you have just connected, fetch again in a moment; otherwise upload a
          journal — the most useful one is a session where you opened the Carrier Management screen, which writes
          a <code>CarrierStats</code> event carrying everything at once.
        </p>
        <div class="actions">
          <?php if (fc_capi_configured()): ?>
            <a class="btn" href="<?= fc_e(fc_url('capi.php')) ?>">Fetch from Frontier</a>
          <?php endif; ?>
          <a class="btn ghost" href="<?= fc_e(fc_url('upload.php')) ?>">Upload a journal</a>
        </div>
      <?php endif; ?>
    </div>
  <?php elseif (count($mine) === 1): ?>
    <?php
    // One carrier: it is the page, so show everything about it.
    $primary = $mine[0];
    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $primary['id']]);
    fc_render_carrier_title($primary, true);
    fc_render_carrier_stats($primary, true);
    ?>

    <div style="margin-top:18px"></div>
    <?php fc_render_upkeep($primary, $crew); ?>

    <div class="grid two" style="margin-top:18px">
      <?php fc_render_space_breakdown($primary); ?>
      <?php fc_render_taxes($primary); ?>
    </div>

    <div class="card">
      <h2>Jump into detail</h2>
      <div class="actions" style="margin-top:0">
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($primary)) ?>">Overview</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($primary)) ?>&amp;tab=market">Market</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($primary)) ?>&amp;tab=orders">Orders</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($primary)) ?>&amp;tab=itinerary">Itinerary</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($primary)) ?>&amp;tab=finance">Finance</a>
        <a class="btn sm" href="<?= fc_e(fc_url('upload.php')) ?>">Upload newer data</a>
      </div>
      <p class="small dim" style="margin-bottom:0">
        Stats last updated <?= fc_e(fc_ago($primary['stats_at'])) ?>,
        market <?= fc_e(fc_ago($primary['market_at'])) ?>,
        finance <?= fc_e(fc_ago($primary['finance_at'])) ?>.
      </p>
    </div>

  <?php else: ?>
    <?php
    // Several: nobody reads six full stat blocks. Anything in trouble comes
    // first, because the reason to open a fleet view is to find it.
    usort($mine, static function (array $a, array $b): int {
        $wa = fc_carrier_warnings($a) === [] ? 1 : 0;
        $wb = fc_carrier_warnings($b) === [] ? 1 : 0;
        return [$wa, $b['updated_at']] <=> [$wb, $a['updated_at']];
    });
    ?>

    <h1>Your carriers</h1>
    <?php fc_render_fleet_summary($mine); ?>

    <div class="grid two">
      <?php foreach ($mine as $carrier) { fc_render_carrier_card($carrier); } ?>
    </div>

    <div class="card">
      <h2>Everything at once</h2>
      <div class="actions" style="margin-top:0">
        <a class="btn ghost sm" href="<?= fc_e(fc_url('upload.php')) ?>">Upload a journal</a>
        <?php if (fc_capi_configured()): ?>
          <a class="btn ghost sm" href="<?= fc_e(fc_url('capi.php')) ?>">Fetch from Frontier</a>
        <?php endif; ?>
        <a class="btn ghost sm" href="<?= fc_e(fc_url('search.php')) ?>">Browse the board</a>
      </div>
      <p class="small dim" style="margin-bottom:0">
        Elite allows one fleet carrier per Frontier account, so watching several means connecting several
        accounts. Each keeps its own Frontier link and updates independently.
      </p>
    </div>

  <?php endif; ?>
</main>
<?php fc_foot();
