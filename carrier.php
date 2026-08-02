<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_render.php';

$user = fc_user();

$id = trim((string) ($_GET['id'] ?? ''));
$carrier = null;
if ($id !== '') {
    $carrier = ctype_digit($id) ? fc_carrier((int) $id) : fc_carrier_by_callsign($id);
}
if ($carrier === null) {
    fc_fail(404, 'No carrier with that id or callsign.');
}

$owns = fc_owns($user, $carrier);

// A private carrier is not browsable at all by anyone but its owner; there is
// no half-visible state to leak a callsign through.
if (!$owns && (int) $carrier['is_public'] !== 1) {
    fc_fail(404, 'No carrier with that id or callsign.');
}

// ---------------------------------------------------------------------------
// Owner actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();
    if (!$owns) {
        fc_fail(403, 'That carrier is not yours.');
    }

    if (($_POST['action'] ?? '') === 'visibility') {
        fc_update_carrier((int) $carrier['id'], [
            'is_public' => isset($_POST['is_public']) ? 1 : 0,
            'show_market' => isset($_POST['show_market']) ? 1 : 0,
            'show_itinerary' => isset($_POST['show_itinerary']) ? 1 : 0,
            'motd' => mb_substr(trim((string) ($_POST['motd'] ?? '')), 0, 500) ?: null,
        ]);
        fc_flash('Carrier settings saved.');
    } elseif (($_POST['action'] ?? '') === 'release') {
        // Hand the carrier back so a different account can claim it. The data
        // stays; only the ownership link is dropped.
        fc_exec('UPDATE fc_carriers SET owner_user_id = NULL WHERE id = :id', ['id' => $carrier['id']]);
        fc_flash('Carrier released. Any account can now claim it by uploading its journal.');
        fc_redirect(fc_url());
    }

    fc_redirect(fc_carrier_link($carrier) . '&tab=manage');
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

$tabs = [
    'overview' => 'Overview',
    'cargo' => 'Cargo',
    'market' => 'Market',
    'orders' => 'Orders',
    'itinerary' => 'Itinerary',
    'shipyard' => 'Shipyard',
    'outfitting' => 'Outfitting',
    'crew' => 'Crew',
];
if ($owns) {
    $tabs['finance'] = 'Finance';
    $tabs['manage'] = 'Manage';
} else {
    // Owner-only wherever they sit in the ordering above.
    unset($tabs['cargo']);
}

$tab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
if (!fc_can_view($user, $carrier, $tab)) {
    fc_fail(403, 'The owner has not made that part of this carrier public.');
}

fc_head(fc_carrier_display_name($carrier), 'search');
?>
<main class="wrap">
  <?php fc_render_flash(); ?>
  <?php fc_render_carrier_title($carrier, $user); ?>

  <?php if ($carrier['motd'] !== null && $carrier['motd'] !== ''): ?>
    <div class="banner"><?= nl2br(fc_e($carrier['motd'])) ?></div>
  <?php endif; ?>

  <div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
      <?php if (!fc_can_view($user, $carrier, $key)) { continue; } ?>
      <a href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=<?= fc_e($key) ?>"<?= $key === $tab ? ' class="on"' : '' ?>><?= fc_e($label) ?></a>
    <?php endforeach; ?>
  </div>

<?php
switch ($tab) {

// ---------------------------------------------------------------------------
case 'overview':
    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
    fc_render_carrier_stats($carrier, $owns);
    echo '<div style="margin-top:18px"></div>';
    if ($owns) {
        fc_render_upkeep($carrier, $crew);
    }
    echo '<div class="grid two">';
    fc_render_space_breakdown($carrier);
    if ($owns) {
        fc_render_taxes($carrier);
    }
    echo '</div>';
    break;

// ---------------------------------------------------------------------------
case 'cargo':
    $rows = fc_all(
        'SELECT * FROM fc_cargo WHERE carrier_id = :id ORDER BY stolen, qty DESC',
        ['id' => $carrier['id']],
    );
    $tonnes = array_sum(array_map(static fn(array $r) => (int) $r['qty'], $rows));
    $worth = array_sum(array_map(static fn(array $r) => (int) $r['qty'] * (int) $r['value'], $rows));
    ?>
    <div class="card">
      <h2>Cargo hold <span class="muted small">updated <?= fc_e(fc_ago($carrier['cargo_at'])) ?></span></h2>
      <?php if ($rows === []): ?>
        <div class="empty">
          The journal never reports what is in a carrier's hold — only the Companion API does.
          <a href="<?= fc_e(fc_url('plugin.php')) ?>">The EDMC plugin</a> can fetch it.
        </div>
      <?php else: ?>
        <div class="stats" style="margin-bottom:16px">
          <div class="stat">
            <div class="k">Tonnes held</div>
            <div class="v"><?= fc_num($tonnes) ?> <span class="muted small">t</span></div>
          </div>
          <div class="stat">
            <div class="k">Estimated worth</div>
            <div class="v"><?= fc_cr($worth) ?> <span class="muted small">cr</span></div>
          </div>
        </div>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Commodity</th><th class="num">Quantity</th><th class="num">Unit value</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <?= fc_e($row['loc_name'] ?: ucfirst($row['commodity'])) ?>
                  <?php if ((int) $row['stolen'] === 1): ?><span class="badge bad">Stolen</span><?php endif; ?>
                </td>
                <td class="num"><?= fc_num((int) $row['qty']) ?> t</td>
                <td class="num"><?= fc_cr((int) $row['value']) ?></td>
                <td class="num"><?= fc_cr((int) $row['qty'] * (int) $row['value']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'market':
    $rows = fc_all(
        'SELECT * FROM fc_market WHERE carrier_id = :id ORDER BY category, commodity',
        ['id' => $carrier['id']],
    );
    ?>
    <div class="card">
      <h2>Commodity market <span class="muted small">updated <?= fc_e(fc_ago($carrier['market_at'])) ?></span></h2>
      <?php if ($rows === []): ?>
        <div class="empty">
          Nothing yet. Upload <code>Market.json</code> from your journal folder while docked at the carrier —
          the game rewrites it every time you open the commodity screen.
        </div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead>
            <tr>
              <th>Commodity</th><th>Category</th>
              <th class="num">Stock</th><th class="num">Demand</th>
              <th class="num">Buy</th><th class="num">Sell</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= fc_e($row['loc_name'] ?: ucfirst($row['commodity'])) ?></td>
                <td class="muted"><?= fc_e($row['category'] ?? '') ?></td>
                <td class="num"><?= fc_num((int) $row['stock']) ?></td>
                <td class="num"><?= fc_num((int) $row['demand']) ?></td>
                <td class="num"><?= (int) $row['buy_price'] === 0 ? '—' : fc_cr((int) $row['buy_price']) ?></td>
                <td class="num"><?= (int) $row['sell_price'] === 0 ? '—' : fc_cr((int) $row['sell_price']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'orders':
    $rows = fc_all(
        'SELECT * FROM fc_orders WHERE carrier_id = :id ORDER BY kind, commodity',
        ['id' => $carrier['id']],
    );
    ?>
    <div class="card">
      <h2>Standing orders</h2>
      <?php if ($rows === []): ?>
        <div class="empty">No buy or sell orders recorded. These come from <code>CarrierTradeOrder</code> events in the journal.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead>
            <tr><th>Commodity</th><th>Order</th><th class="num">Amount</th><th class="num">Price</th><th class="num">Total</th><th>Set</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <?= fc_e($row['loc_name'] ?: ucfirst($row['commodity'])) ?>
                  <?php if ((int) $row['black_market'] === 1): ?><span class="badge warn">Black market</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($row['kind'] === 'buy'): ?>
                    <span class="badge accent">Buying</span>
                  <?php else: ?>
                    <span class="badge on">Selling</span>
                  <?php endif; ?>
                </td>
                <td class="num"><?= fc_num((int) $row['amount']) ?> t</td>
                <td class="num"><?= fc_cr((int) $row['price']) ?></td>
                <td class="num"><?= fc_cr((int) $row['amount'] * (int) $row['price']) ?></td>
                <td class="muted small nowrap"><?= fc_e(fc_ago($row['updated_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'itinerary':
    $stops = fc_all(
        'SELECT * FROM fc_itinerary WHERE carrier_id = :id ORDER BY arrival_time DESC LIMIT 200',
        ['id' => $carrier['id']],
    );
    $jumps = fc_all(
        'SELECT * FROM fc_jumps WHERE carrier_id = :id ORDER BY departure_time DESC LIMIT 50',
        ['id' => $carrier['id']],
    );
    ?>
    <div class="card">
      <h2>Where it has been</h2>
      <?php if ($stops === []): ?>
        <div class="empty">No arrivals recorded yet. Every <code>CarrierJump</code> in an uploaded journal adds one.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead><tr><th>System</th><th>Body</th><th>Arrived</th><th>Departed</th><th class="num">Stayed</th></tr></thead>
            <tbody>
            <?php foreach ($stops as $stop):
                $stayed = null;
                if ($stop['departure_time'] !== null) {
                    $stayed = strtotime($stop['departure_time'] . ' UTC') - strtotime($stop['arrival_time'] . ' UTC');
                }
                ?>
              <tr>
                <td><?= fc_e($stop['system']) ?></td>
                <td class="muted"><?= fc_e($stop['body'] ?? '—') ?></td>
                <td class="nowrap"><?= fc_e(fc_dt($stop['arrival_time'])) ?></td>
                <td class="nowrap muted"><?= $stop['departure_time'] === null ? '<span class="badge on">Here now</span>' : fc_e(fc_dt($stop['departure_time'])) ?></td>
                <td class="num muted"><?= $stayed === null ? '—' : fc_e(fc_duration(max(0, $stayed))) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($jumps !== []): ?>
      <div class="card">
        <h2>Jump log</h2>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Destination</th><th>Body</th><th>Departure</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($jumps as $jump): ?>
              <tr>
                <td><?= fc_e($jump['system'] ?? '—') ?></td>
                <td class="muted"><?= fc_e($jump['body'] ?? '—') ?></td>
                <td class="nowrap"><?= fc_e(fc_dt($jump['departure_time'])) ?></td>
                <td>
                  <?php
                  $class = match ($jump['status']) {
                      'completed' => 'on',
                      'cancelled' => 'bad',
                      default => 'warn',
                  };
                  ?>
                  <span class="badge <?= $class ?>"><?= fc_e(ucfirst((string) $jump['status'])) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'shipyard':
    $rows = fc_all('SELECT * FROM fc_shipyard WHERE carrier_id = :id ORDER BY base_value DESC', ['id' => $carrier['id']]);
    ?>
    <div class="card">
      <h2>Shipyard <span class="muted small">updated <?= fc_e(fc_ago($carrier['shipyard_at'])) ?></span></h2>
      <?php if ($rows === []): ?>
        <div class="empty">Nothing yet. Upload <code>Shipyard.json</code> while docked at the carrier.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Ship</th><th class="num">Base value</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= fc_e($row['loc_name'] ?: ucfirst($row['ship'])) ?></td>
                <td class="num"><?= fc_cr((int) $row['base_value']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'outfitting':
    $rows = fc_all('SELECT * FROM fc_outfitting WHERE carrier_id = :id ORDER BY category, loc_name', ['id' => $carrier['id']]);
    ?>
    <div class="card">
      <h2>Outfitting <span class="muted small">updated <?= fc_e(fc_ago($carrier['outfitting_at'])) ?></span></h2>
      <?php if ($rows === []): ?>
        <div class="empty">Nothing yet. Upload <code>Outfitting.json</code> while docked at the carrier.</div>
      <?php else: ?>
        <p class="muted small"><?= count($rows) ?> modules stocked.</p>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Module</th><th>Category</th><th class="num">Cost</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= fc_e($row['loc_name'] ?: $row['module']) ?></td>
                <td class="muted"><?= fc_e($row['category'] ?? '') ?></td>
                <td class="num"><?= fc_cr((int) $row['cost']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'crew':
    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
    usort($crew, static fn(array $a, array $b) => strcmp(fc_service_label($a['crew_role']), fc_service_label($b['crew_role'])));
    ?>
    <div class="card">
      <h2>Crew and services</h2>
      <?php if ($crew === []): ?>
        <div class="empty">No roster yet. Open the Carrier Management screen in game and upload that journal —
          it writes a <code>CarrierStats</code> event with the full crew list.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Service</th><th>Officer</th><th>Name</th><th>State</th></tr></thead>
            <tbody>
            <?php foreach ($crew as $member):
                $spec = fc_service((string) $member['crew_role']);
                ?>
              <tr>
                <td><?= fc_e($spec['label'] ?? $member['crew_role']) ?><?php if ($spec['core'] ?? false): ?>
                  <span class="badge">Core</span><?php endif; ?></td>
                <td class="muted"><?= fc_e($spec['officer'] ?? '—') ?></td>
                <td><?= fc_e($member['crew_name'] ?? '—') ?></td>
                <td>
                  <?php if ((int) $member['activated'] !== 1): ?>
                    <span class="badge off">Not installed</span>
                  <?php elseif ((int) $member['enabled'] === 1): ?>
                    <span class="badge on">Active</span>
                  <?php else: ?>
                    <span class="badge warn">Suspended</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'finance':
    $ledger = fc_all(
        'SELECT * FROM fc_ledger WHERE carrier_id = :id ORDER BY ts DESC LIMIT 200',
        ['id' => $carrier['id']],
    );
    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $carrier['id']]);
    fc_render_upkeep($carrier, $crew);
    fc_render_taxes($carrier);
    ?>
    <div class="card">
      <h2>Ledger</h2>
      <?php if ($ledger === []): ?>
        <div class="empty">No transactions recorded. Bank transfers, tritium deposits and pack purchases all show up here.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead><tr><th>When</th><th>Kind</th><th>Detail</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $entry):
                $amount = $entry['amount'] === null ? null : (int) $entry['amount'];
                $unit = (string) $entry['unit'];
                ?>
              <tr>
                <td class="nowrap muted"><?= fc_e(fc_dt($entry['ts'])) ?></td>
                <td><span class="badge"><?= fc_e($entry['kind']) ?></span></td>
                <td><?= fc_e($entry['detail'] ?? '') ?></td>
                <td class="num" style="<?= $amount !== null && $amount < 0 ? 'color:var(--danger)' : ($amount !== null && $amount > 0 ? 'color:var(--ok)' : '') ?>">
                  <?= $amount === null ? '—' : ($amount > 0 ? '+' : '') . fc_cr($amount) ?>
                  <span class="muted small"><?= fc_e($unit) ?></span>
                </td>
                <td class="num muted"><?= fc_cr($entry['balance'] === null ? null : (int) $entry['balance']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    break;

// ---------------------------------------------------------------------------
case 'manage':
    ?>
    <div class="card">
      <h2>Visibility</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="visibility">

        <div class="check">
          <input type="checkbox" id="is_public" name="is_public" <?= (int) $carrier['is_public'] === 1 ? 'checked' : '' ?>>
          <label for="is_public">Listed publicly — anyone with the link can see the overview, crew and jump history.</label>
        </div>
        <div class="check">
          <input type="checkbox" id="show_market" name="show_market" <?= (int) $carrier['show_market'] === 1 ? 'checked' : '' ?>>
          <label for="show_market">Show the market, shipyard and outfitting stock publicly.</label>
        </div>
        <div class="check">
          <input type="checkbox" id="show_itinerary" name="show_itinerary" <?= (int) $carrier['show_itinerary'] === 1 ? 'checked' : '' ?>>
          <label for="show_itinerary">Show the itinerary publicly.</label>
        </div>

        <div class="field" style="margin-top:16px">
          <label for="motd">Message of the day</label>
          <textarea id="motd" name="motd" rows="3" maxlength="500"><?= fc_e($carrier['motd'] ?? '') ?></textarea>
        </div>

        <p class="small dim">Finances are never public, whatever these are set to.</p>

        <div class="actions">
          <button class="btn" type="submit">Save</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Identity</h2>
      <div class="tablewrap">
        <table>
          <tbody>
          <tr><td>Carrier ID</td><td class="mono"><?= fc_e((string) $carrier['id']) ?></td></tr>
          <tr><td>Callsign</td><td class="mono"><?= fc_e($carrier['callsign'] ?? '—') ?></td></tr>
          <tr><td>Market ID</td><td class="mono"><?= fc_e((string) ($carrier['market_id'] ?? '—')) ?></td></tr>
          <tr><td>Claimed</td><td><?= fc_e(fc_dt($carrier['created_at'])) ?></td></tr>
          </tbody>
        </table>
      </div>
      <p class="small dim">Callsign and name come from the journal and cannot be edited here — change them in game and upload again.</p>
    </div>

    <div class="card">
      <h2>Release</h2>
      <p class="muted small">
        Releasing unlinks the carrier from your account without deleting anything. Another account can then claim it
        by uploading a journal containing its owner-only events. Use this if the carrier changed hands, or if you
        claimed it from the wrong account.
      </p>
      <form method="post" onsubmit="return confirm('Release this carrier? Any account will then be able to claim it.')">
        <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
        <input type="hidden" name="action" value="release">
        <div class="actions">
          <button class="btn danger" type="submit">Release carrier</button>
        </div>
      </form>
    </div>
    <?php
    break;
}
?>
</main>
<?php fc_foot();
