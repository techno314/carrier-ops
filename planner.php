<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';

$user = fc_user();
$zip = __DIR__ . '/assets/colony-planner.zip';
$size = @filesize($zip);
$mtime = @filemtime($zip);

// Same reason as the plugin download: Cloudflare caches by URL, so a rebuilt
// zip under the same name keeps being served from the edge. Version the link.
$href = '/fc/assets/colony-planner.zip' . ($mtime === false ? '' : '?v=' . $mtime);

$version = null;
if (is_file($zip)) {
    $archive = new ZipArchive();
    if ($archive->open($zip) === true) {
        $source = $archive->getFromName('colony_planner.py');
        $archive->close();
        if (is_string($source) && preg_match('/^VERSION\s*=\s*"([^"]+)"/m', $source, $found)) {
            $version = $found[1];
        }
    }
}

fc_head('Colony Planner', 'planner');
?>
<main class="wrap mid">
  <h1>Colony Planner<?= $version === null ? '' : ' <span class="badge">v' . fc_e($version) . '</span>' ?></h1>
  <p class="muted">
    What a construction site still needs, and whether anybody hauling to it is already carrying it.
    A small desktop program that reads your Elite journal — it is not part of this board, it talks to it.
  </p>

  <details class="card" open>
    <summary><h2>Download</h2></summary>
    <div class="actions" style="margin-top:0">
      <a class="btn" href="<?= fc_e($href) ?>" download="colony-planner.zip">
        colony-planner.zip<?= $size === false ? '' : ' · ' . number_format($size / 1024, 1) . ' KB' ?>
      </a>
    </div>
    <p class="small dim" style="margin-bottom:0">
      One Python file and a launcher. Nothing to install on Windows; on Linux you need Tk, which most
      distributions package separately — the program says which package if it is missing.
      It updates itself after this, so this is the only download.
    </p>
  </details>

  <details class="card">
    <summary><h2>The problem it solves</h2></summary>
    <p class="muted small">
      The construction panel is readable in exactly one place: docked at the site. That is the one place the
      answer has stopped being useful, because by then you have chosen a load, flown out, and filled the hold
      with something else.
    </p>
    <p class="muted small" style="margin-bottom:0">
      The journal has all of it. Every time that panel updates the game writes the whole manifest — every
      commodity, how much is wanted, how much has arrived. The planner reads that and shows what is left,
      minus whatever is already in your hold or on your carrier.
    </p>
  </details>

  <details class="card">
    <summary><h2>Hauling with other people</h2></summary>
    <p class="muted small">
      A commander's journal is a private account of one person's evening. It knows what the depot said when
      they last docked and nothing at all about the four other people flying to the same site — so two of them
      buy fifty thousand tonnes of steel for a site that wanted seventy, and neither finds out until the second
      one arrives.
    </p>
    <p class="muted small">
      Point the planner at this board and those separate views are added together. Everybody sees the freshest
      manifest, whoever read it, and a column showing what the rest of the crew is already carrying — so a
      commodity the group has covered between them stops appearing on anybody's shopping list.
    </p>
    <p class="small dim" style="margin-bottom:0">
      Invitations are per system and need no account here: whoever is hauling presses <strong>Invite…</strong>
      and pastes the token to the next person. It works on that colony and nothing else on this board.
    </p>
  </details>

  <details class="card">
    <summary><h2>Setting it up</h2></summary>
    <ol class="muted" style="line-height:1.8">
      <li>Extract the zip anywhere and run <code>Colony Planner.bat</code>, or <code>colony-planner.sh</code> on Linux.</li>
      <li>Dock at a construction site once. The game writes the manifest and the site appears.</li>
      <li>Press <strong>Carrier…</strong> and paste an API key to see your carrier's hold and to share the build.</li>
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
    <p class="small dim" style="margin-bottom:0">
      A key is only needed for the carrier hold and for sharing. Everything about the site itself comes from
      your own journal and works without one.
    </p>
  </details>

  <details class="card">
    <summary><h2>Updating</h2></summary>
    <p class="muted small">
      It checks this board on startup and every half hour after. When a newer version is here, a button appears
      beside <em>Follow the journal</em>; pressing it downloads the new planner, writes it over the old one and
      restarts. Nothing else is required, and nothing is shown while you are current.
    </p>
  </details>
</main>
<?php fc_foot();
