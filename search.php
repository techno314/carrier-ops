<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_render.php';

$user = fc_user();
$query = trim((string) ($_GET['q'] ?? ''));

/** Escape LIKE wildcards so a search for "%" matches a literal percent sign. */
function fc_like(string $search): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
}

if ($query !== '') {
    $carriers = fc_all(
        'SELECT * FROM fc_carriers
          WHERE is_public = 1
            AND (name LIKE :q OR callsign LIKE :q2 OR system LIKE :q3)
          ORDER BY updated_at DESC
          LIMIT 100',
        ['q' => fc_like($query), 'q2' => fc_like($query), 'q3' => fc_like($query)],
    );
} else {
    $carriers = fc_all(
        'SELECT * FROM fc_carriers WHERE is_public = 1 ORDER BY updated_at DESC LIMIT 50',
    );
}

$total = (int) (fc_one('SELECT COUNT(*) AS n FROM fc_carriers WHERE is_public = 1')['n'] ?? 0);

fc_head('Carriers', 'search');
?>
<main class="wrap">
  <h1>Carriers</h1>
  <p class="muted">
    <?= fc_num($total) ?> listed. Owners choose whether their carrier appears here, and a carrier tracked only from
    visitors' journals shows what it was last seen doing.
  </p>

  <div class="card">
    <form method="get">
      <div class="field">
        <label for="q">Search by name, callsign or system</label>
        <input id="q" name="q" type="search" value="<?= fc_e($query) ?>" placeholder="e.g. Spirula, L14-X1J, or Colonia" autofocus>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Search</button>
        <?php if ($query !== ''): ?>
          <a class="btn ghost" href="<?= fc_e(fc_url('search.php')) ?>">Clear</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2><?= $query === '' ? 'Recently updated' : 'Results' ?></h2>
    <?php if ($carriers === []): ?>
      <div class="empty">
        <?= $query === '' ? 'Nothing listed yet.' : 'No carrier matches that.' ?>
      </div>
    <?php else: ?>
      <div class="tablewrap">
        <table>
          <thead><tr><th>Carrier</th><th>System</th><th class="num">Fuel</th><th>Docking</th><th>Updated</th></tr></thead>
          <tbody>
          <?php foreach ($carriers as $carrier) { fc_render_carrier_row($carrier); } ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php fc_foot();
