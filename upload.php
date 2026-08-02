<?php

declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ingest.php';
require __DIR__ . '/lib/render.php';

$user = fc_require_user();
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();

    $files = $_FILES['journals'] ?? null;
    if ($files === null || !is_array($files['name'])) {
        $error = 'Choose at least one file.';
    } else {
        $totals = ['seen' => 0, 'applied' => 0, 'carriers' => [], 'notes' => [], 'files' => 0];

        foreach ($files['name'] as $i => $name) {
            if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
                $totals['notes'][] = 'Could not read ' . basename((string) $name) . '.';
                continue;
            }
            if ((int) $files['size'][$i] > FC_MAX_UPLOAD_BYTES) {
                $totals['notes'][] = basename((string) $name) . ' is too large.';
                continue;
            }

            $text = file_get_contents($files['tmp_name'][$i]);
            if ($text === false) {
                $totals['notes'][] = 'Could not read ' . basename((string) $name) . '.';
                continue;
            }

            $report = fc_ingest_text($text, $user, basename((string) $name));
            $totals['files']++;
            $totals['seen'] += $report['seen'];
            $totals['applied'] += $report['applied'];
            $totals['carriers'] += $report['carriers'];
            foreach ($report['notes'] as $note) {
                if (!in_array($note, $totals['notes'], true)) {
                    $totals['notes'][] = $note;
                }
            }
        }

        if ($totals['files'] === 0) {
            $error = 'No files were received.';
        } else {
            $result = $totals;
        }
    }
}

$recent = fc_all(
    'SELECT * FROM fc_uploads WHERE user_id = :uid ORDER BY ts DESC LIMIT 10',
    ['uid' => $user['id']],
);

fc_head('Upload', 'upload');
?>
<main class="wrap mid">
  <h1>Upload journal data</h1>
  <p class="muted">
    Drop in one or more files from <code>%USERPROFILE%\Saved Games\Frontier Developments\Elite Dangerous</code>.
    Only carrier events are read; the rest of the journal is discarded on the spot and never stored.
  </p>

  <?php if ($error !== null): ?>
    <div class="banner err"><?= fc_e($error) ?></div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
    <div class="banner">
      Read <?= (int) $result['files'] ?> file<?= $result['files'] === 1 ? '' : 's' ?>,
      found <?= fc_num($result['seen']) ?> event<?= $result['seen'] === 1 ? '' : 's' ?>
      and applied <?= fc_num($result['applied']) ?>.
      <?php if ($result['carriers'] !== []): ?>
        <div style="margin-top:8px">
          <?php foreach ($result['carriers'] as $cid => $label): ?>
            <a class="btn ghost sm" href="<?= fc_e(fc_url('carrier.php?id=' . (int) $cid)) ?>"><?= fc_e($label) ?></a>
          <?php endforeach; ?>
        </div>
      <?php elseif ($result['notes'] === []): ?>
        <?php // Only guess at the reason when nothing more specific was found. ?>
        <div style="margin-top:6px" class="small">
          No carrier events in those files. The most useful one is a session where you opened the Carrier
          Management screen — that writes <code>CarrierStats</code>, which carries everything at once.
        </div>
      <?php endif; ?>
    </div>
    <?php foreach ($result['notes'] as $note): ?>
      <div class="banner warn"><?= fc_e($note) ?></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="card">
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">

      <label class="drop" id="drop" for="journals">
        <strong>Drop journal files here</strong>
        or click to choose. <code>Journal.*.log</code>, <code>Market.json</code>,
        <code>Shipyard.json</code>, <code>Outfitting.json</code>.
        <input id="journals" name="journals[]" type="file" multiple hidden
               accept=".log,.json,application/json,text/plain">
      </label>

      <div class="filelist" id="filelist"></div>

      <div class="actions">
        <button class="btn" type="submit" id="go" disabled>Upload</button>
        <span class="small dim">Up to <?= (int) (FC_MAX_UPLOAD_BYTES / 1048576) ?> MB per file.</span>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Which files to pick</h2>
    <div class="tablewrap">
      <table>
        <tbody>
        <tr><td><code>Journal.*.log</code></td><td class="muted">Everything: crew, finance, fuel, jumps, trade orders. Written continuously; the newest file is the current session.</td></tr>
        <tr><td><code>Market.json</code></td><td class="muted">The commodity market. Rewritten each time you open the commodity screen while docked at the carrier.</td></tr>
        <tr><td><code>Shipyard.json</code></td><td class="muted">Ships in stock. Written when you open the shipyard.</td></tr>
        <tr><td><code>Outfitting.json</code></td><td class="muted">Modules in stock. Written when you open outfitting.</td></tr>
        </tbody>
      </table>
    </div>
    <p class="small dim" style="margin-bottom:0">
      Uploading the same file twice is harmless — events are matched on their own timestamps, and older data
      never overwrites newer.
    </p>
  </div>

  <div class="card">
    <h2>Stop doing this by hand</h2>
    <p class="muted small">
      The <a href="<?= fc_e(fc_url('plugin.php')) ?>">EDMC plugin</a> watches your journal and pushes carrier events
      as they happen, including a one-press backfill of everything already on disk. Failing that, an API key on your
      <a href="<?= fc_e(fc_url('settings.php')) ?>">settings page</a> lets any script post to the same endpoint.
    </p>
    <div class="actions">
      <a class="btn ghost sm" href="<?= fc_e(fc_url('plugin.php')) ?>">Get the plugin</a>
    </div>
  </div>

  <?php if ($recent !== []): ?>
    <div class="card">
      <h2>Recent uploads</h2>
      <div class="tablewrap">
        <table>
          <thead><tr><th>When</th><th>File</th><th>Source</th><th class="num">Events</th><th class="num">Applied</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td class="nowrap muted"><?= fc_e(fc_ago($row['ts'])) ?></td>
              <td class="mono small"><?= fc_e($row['filename'] ?? '—') ?></td>
              <td class="muted small"><?= fc_e($row['source']) ?></td>
              <td class="num"><?= fc_num((int) $row['events_seen']) ?></td>
              <td class="num"><?= fc_num((int) $row['events_applied']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</main>

<script>
(function () {
  var drop = document.getElementById('drop');
  var input = document.getElementById('journals');
  var list = document.getElementById('filelist');
  var go = document.getElementById('go');

  function human(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function render() {
    var files = input.files;
    list.innerHTML = '';
    for (var i = 0; i < files.length; i++) {
      var row = document.createElement('div');
      var name = document.createElement('span');
      name.textContent = files[i].name;
      var size = document.createElement('span');
      size.className = 'dim';
      size.textContent = human(files[i].size);
      row.appendChild(name);
      row.appendChild(size);
      list.appendChild(row);
    }
    go.disabled = files.length === 0;
  }

  input.addEventListener('change', render);

  ['dragenter', 'dragover'].forEach(function (type) {
    drop.addEventListener(type, function (e) {
      e.preventDefault();
      drop.classList.add('hot');
    });
  });
  ['dragleave', 'drop'].forEach(function (type) {
    drop.addEventListener(type, function (e) {
      e.preventDefault();
      drop.classList.remove('hot');
    });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      render();
    }
  });
})();
</script>
<?php fc_foot();
