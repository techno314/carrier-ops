<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$user = fc_user();
$zip = __DIR__ . '/assets/CarrierOps-edmc.zip';
$size = @filesize($zip);

fc_head('EDMC plugin', 'upload');
?>
<main class="wrap mid">
  <h1>EDMC plugin</h1>
  <p class="muted">
    Uploading files by hand gets old. This plugin for
    <a href="https://github.com/EDCD/EDMarketConnector" rel="noopener">EDMarketConnector</a> watches the journal and
    pushes carrier events as they happen, so the board keeps itself current while you play.
  </p>

  <div class="card">
    <h2>Download</h2>
    <div class="actions" style="margin-top:0">
      <a class="btn" href="/fc/assets/CarrierOps-edmc.zip" download>
        CarrierOps-edmc.zip<?= $size === false ? '' : ' · ' . number_format($size / 1024, 1) . ' KB' ?>
      </a>
    </div>
    <p class="small dim" style="margin-bottom:0">One Python file and a readme. No dependencies beyond what EDMC already ships.</p>
  </div>

  <div class="card">
    <h2>Install</h2>
    <ol class="muted" style="line-height:1.8">
      <li>In EDMC: <strong>File → Settings → Plugins → Open</strong>. That opens your plugins folder.</li>
      <li>Extract the zip into it, so you end up with a <code>CarrierOps</code> folder containing <code>load.py</code>.</li>
      <li>Restart EDMC.</li>
      <li><strong>File → Settings → Carrier Ops</strong>, paste your API key, press <strong>Test connection</strong>.</li>
      <li>Press <strong>Upload past journals</strong> once — it backfills every carrier event you still have on disk.</li>
    </ol>
    <?php if ($user !== null): ?>
      <div class="actions">
        <a class="btn ghost" href="<?= fc_e(fc_url('settings.php')) ?>">Get an API key</a>
      </div>
    <?php else: ?>
      <div class="actions">
        <a class="btn ghost" href="<?= fc_e(fc_url('login.php')) ?>">Sign in to get an API key</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>What it sends</h2>
    <p class="muted small">
      Only fleet carrier events, plus the <code>Market.json</code>, <code>Shipyard.json</code> and
      <code>Outfitting.json</code> snapshots when they belong to a carrier. Every other journal entry is checked for
      its event name and dropped — nothing about where you have been, what you scanned, who you fought or what was
      said in chat leaves your machine.
    </p>
    <p class="muted small" style="margin-bottom:0">
      Carrier events are batched every 20 seconds. <code>CarrierStats</code>, <code>CarrierJump</code>,
      <code>CarrierJumpRequest</code>, <code>CarrierJumpCancelled</code> and <code>CarrierLocation</code> go
      straight out, since those are the ones worth watching a board for.
    </p>
  </div>

  <div class="card">
    <h2>If you would rather script it</h2>
    <p class="muted small">The plugin is a convenience over the same endpoint anything can post to:</p>
    <pre class="code">curl -X POST <?= fc_e(fc_url('api.php?action=ingest')) ?> \
  -H "X-API-Key: YOUR_KEY" \
  --data-binary @Journal.2026-08-02T120000.01.log</pre>
  </div>
</main>
<?php fc_foot();
