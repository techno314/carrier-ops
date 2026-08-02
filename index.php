<?php

declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/render.php';

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
          There is no Frontier account linking and no third-party API to break. You upload a journal, or point a
          script at the upload endpoint, and the board keeps itself current.
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
      <p class="muted">
        Upload a journal containing your carrier's events and it will appear here. The quickest one to find is the
        most recent <code>Journal.*.log</code> from a session where you docked at your own carrier — opening the
        Carrier Management screen writes a <code>CarrierStats</code> event, which carries everything at once.
      </p>
      <div class="actions">
        <a class="btn" href="<?= fc_e(fc_url('upload.php')) ?>">Upload a journal</a>
      </div>
    </div>
  <?php else: ?>
    <?php
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

    <?php if (count($mine) > 1): ?>
      <div class="card">
        <h2>Your other carriers</h2>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Carrier</th><th>System</th><th class="num">Fuel</th><th>Docking</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($mine, 1) as $carrier) { fc_render_carrier_row($carrier); } ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php fc_foot();
