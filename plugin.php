<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';

$user = fc_user();
$zip = __DIR__ . '/assets/CarrierOps-edmc.zip';
$size = @filesize($zip);

// Cloudflare caches the zip by URL, and a rebuilt plugin under the same name
// would keep being served from the edge. Version the link by mtime so a new
// build is a new URL.
$mtime = @filemtime($zip);
$href = '/fc/assets/CarrierOps-edmc.zip' . ($mtime === false ? '' : '?v=' . $mtime);

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
      <a class="btn" href="<?= fc_e($href) ?>" download="CarrierOps-edmc.zip">
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
        <a class="btn ghost" href="<?= fc_e(fc_url('account.php?do=login')) ?>">Sign in to get an API key</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Exact upkeep and the cargo hold</h2>
    <p class="muted small">
      Two things are not in the journal at all: what the game actually charges for upkeep, and what is in the
      carrier's hold. Both come from Frontier's Companion API — and EDMC already holds an approved client id for it.
    </p>
    <p class="muted small">
      Tick <strong>Enable Fleet Carrier CAPI Queries</strong> in EDMC's <em>Configuration</em> tab. The plugin
      forwards each payload, the upkeep panel switches from <em>estimated</em> to the game's own figures, and a
      Cargo tab appears.
    </p>
    <p class="small dim" style="margin-bottom:0">
      Needs nothing from Frontier. You <em>can</em> register your own Companion API client, but its refresh tokens
      expire 25 days after you authorise — going through EDMC has no such expiry.
    </p>
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
