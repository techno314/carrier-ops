<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/market.php';
require_once __DIR__ . '/lib/webhooks.php';

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
    } elseif (str_starts_with((string) ($_POST['action'] ?? ''), 'webhook_')) {
        fc_handle_webhook_post((string) $_POST['action'], $carrier);
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
  <?php fc_render_carrier_title($carrier); ?>

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
    // The Companion API values a stack as a whole, so this is a sum and not a
    // sum of products.
    $worth = array_sum(array_map(static fn(array $r) => (int) $r['value'], $rows));
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
            <thead><tr><th>Commodity</th><th class="num">Quantity</th><th class="num">Value</th><th class="num">Per tonne</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $qty = (int) $row['qty'];
                $value = (int) $row['value'];
                ?>
              <tr>
                <td>
                  <?= fc_e($row['loc_name'] ?: ucfirst($row['commodity'])) ?>
                  <?php if ((int) $row['stolen'] === 1): ?><span class="badge bad">Stolen</span><?php endif; ?>
                </td>
                <td class="num"><?= fc_num($qty) ?> t</td>
                <td class="num"><?= fc_cr($value) ?></td>
                <td class="num muted"><?= $qty > 0 && $value > 0 ? fc_cr((int) round($value / $qty)) : '—' ?></td>
                <td class="right">
                  <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=cargo&amp;find=<?= fc_e(rawurlencode((string) $row['commodity'])) ?>">Sell where?</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php
    // ---- where to sell it -------------------------------------------------
    $find = trim((string) ($_GET['find'] ?? ''));
    if ($find !== '' && $rows !== []) {
        $stack = null;
        foreach ($rows as $row) {
            if (strcasecmp((string) $row['commodity'], $find) === 0) {
                $stack = $row;
                break;
            }
        }

        if ($stack === null) {
            echo '<div class="card"><div class="empty">That commodity is not in the hold.</div></div>';
            break;
        }

        $qty = (int) $stack['qty'];
        $label = $stack['loc_name'] ?: ucfirst((string) $stack['commodity']);
        $range = fc_search_range($_GET['ly'] ?? 1000);

        if ($carrier['system'] === null) {
            echo '<div class="card"><div class="empty">The carrier has no known position to search from.</div></div>';
            break;
        }

        $buyers = fc_find_buyers((string) $stack['commodity'], (string) $carrier['system'], $qty, $range);
        ?>
        <div class="card">
          <h2>Where to sell <?= fc_e($label) ?>
            <span class="muted small"><?= fc_num($qty) ?> t, within <?= fc_num($range) ?> ly of <?= fc_e($carrier['system']) ?></span>
          </h2>

          <?php if ($buyers['retry_after'] > 0): ?>
            <?php
            // Deferred, not dropped. The page comes back on its own once a
            // slot has actually freed — the waiting happens here rather than
            // in a PHP worker.
            $wait = (int) $buyers['retry_after'];
            ?>
            <div class="banner warn" id="fcWait">
              Other lookups are in flight. Asking again in <span id="fcWaitSecs"><?= $wait ?></span>s —
              leave this open, nothing has been lost.
            </div>
            <script>
            (function () {
              var left = <?= $wait ?>;
              var el = document.getElementById('fcWaitSecs');
              setInterval(function () {
                left -= 1;
                if (el) { el.textContent = left > 0 ? left : 0; }
                if (left <= 0) { location.reload(); }
              }, 1000);
            })();
            </script>
          <?php elseif ($buyers['error'] !== null): ?>
            <div class="banner err"><?= fc_e($buyers['error']) ?></div>
          <?php elseif ($buyers['rows'] === []): ?>
            <div class="empty">Nobody within <?= fc_num($range) ?> ly is buying <?= fc_e($label) ?>.</div>
          <?php else: ?>
            <?php if ($buyers['relaxed']): ?>
              <div class="banner warn">
                No station within <?= fc_num($range) ?> ly has demand for the whole <?= fc_num($qty) ?> t.
                These are the best buyers regardless of how much they will take — check the demand column
                before hauling.
              </div>
            <?php endif; ?>

            <?php
            // The game's own valuation of the stack sits alongside the best
            // listed price, because the two answer different questions and
            // people reasonably expect them to agree.
            $listed = (int) $stack['value'];
            $best = $buyers['rows'][0];
            $atBest = min($qty, $best['demand']) * $best['sellPrice'];
            ?>
            <div class="stats" style="margin-bottom:16px">
              <div class="stat">
                <div class="k">Valued in game at</div>
                <div class="v"><?= fc_cr($listed) ?> <span class="muted small">cr</span></div>
                <div class="muted small" style="margin-top:6px">
                  <?= $qty > 0 && $listed > 0 ? fc_cr((int) round($listed / $qty)) . ' /t' : '—' ?>
                </div>
              </div>
              <div class="stat">
                <div class="k">At the best listed price</div>
                <div class="v"><?= fc_cr($atBest) ?> <span class="muted small">cr</span></div>
                <div class="muted small" style="margin-top:6px"><?= fc_cr($best['sellPrice']) ?> /t at <?= fc_e($best['station']) ?></div>
              </div>
              <?php if ($listed > 0): ?>
                <div class="stat">
                  <div class="k">Difference</div>
                  <div class="v" style="color:<?= $atBest >= $listed ? 'var(--ok)' : 'var(--danger)' ?>">
                    <?= ($atBest >= $listed ? '+' : '') . fc_cr($atBest - $listed) ?>
                  </div>
                  <div class="muted small" style="margin-top:6px">
                    <?= sprintf('%+.1f%%', ($atBest / $listed - 1) * 100) ?> over the in-game valuation
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="tablewrap">
              <table>
                <thead>
                <tr>
                  <th>Station</th><th>System</th>
                  <th class="num">Distance</th><th class="num">Pad</th>
                  <th class="num">Demand</th><th class="num">Price</th>
                  <th class="num">Your stack</th><th>Priced</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($buyers['rows'], 0, 15) as $b):
                    $takes = min($qty, $b['demand']);
                    $stale = fc_price_is_stale($b['updatedAt']);
                    ?>
                  <tr<?= $stale ? ' style="opacity:.55"' : '' ?>>
                    <td><?= fc_e($b['station']) ?></td>
                    <td class="muted"><?= fc_e($b['system']) ?></td>
                    <td class="num"><?= fc_num((int) round($b['distance'])) ?> ly</td>
                    <td class="num muted"><?= fc_e(fc_pad_label($b['pad'])) ?></td>
                    <td class="num<?= $b['demand'] >= $qty ? '' : ' muted' ?>"><?= fc_num($b['demand']) ?> t</td>
                    <td class="num"><?= fc_cr($b['sellPrice']) ?></td>
                    <td class="num"><?= fc_cr($takes * $b['sellPrice']) ?></td>
                    <td class="muted small nowrap">
                      <?= fc_e(fc_ago(substr(str_replace('T', ' ', $b['updatedAt']), 0, 19))) ?>
                      <?php if ($stale): ?><span class="badge">stale</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <p class="small dim" style="margin-bottom:0">
              "Your stack" is the listed price times as much of your <?= fc_num($qty) ?> t as the buyer will take.
              Treat it as a ceiling rather than a quote: a commodity market's price falls as you sell into it, so
              unloading <?= fc_num($qty) ?> t will not hold the top price all the way down. The in-game valuation
              beside it is closer to the galactic average, which is why the two disagree.
              Market data from <a href="https://ardent-insight.com/" rel="noopener">Ardent</a>, cached for six hours;
              fleet carriers excluded, since their owners set arbitrary prices and they move.
              <?php if ($buyers['fetched_at'] !== null): ?>
                Looked up <?= fc_e(fc_ago($buyers['fetched_at'])) ?>.
              <?php endif; ?>
            </p>

            <div class="actions">
              <?php foreach (FC_SEARCH_RANGES as $option): ?>
                <?php if ($option !== $range): ?>
                  <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=cargo&amp;find=<?= fc_e(rawurlencode((string) $stack['commodity'])) ?>&amp;ly=<?= $option ?>">Within <?= fc_num($option) ?> ly</a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }
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
        <?php fc_render_empty(
            $carrier['market_at'],
            'Nothing on the market.',
            'Nothing yet. Upload <code>Market.json</code> from your journal folder while docked at the carrier — '
                . 'the game rewrites it every time you open the commodity screen.',
        ); ?>
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
      <h2>Standing orders
        <?php if ($carrier['orders_at'] !== null): ?>
          <span class="muted small">confirmed <?= fc_e(fc_ago($carrier['orders_at'])) ?></span>
        <?php endif; ?>
      </h2>

      <?php if ($rows !== [] && $carrier['orders_at'] === null): ?>
        <div class="banner warn">
          These come from <code>CarrierTradeOrder</code> events in the journal, which record an order being
          <em>placed</em> or <em>cancelled</em> — never filled. So orders that quietly completed are still listed here.
          <?php if ($owns): ?>
            The <a href="<?= fc_e(fc_url('plugin.php')) ?>">EDMC plugin</a> replaces this with the live order book
            the next time it reads the Companion API.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($rows === []): ?>
        <div class="empty">
          <?php if ($carrier['orders_at'] !== null): ?>
            No buy or sell orders standing.
          <?php else: ?>
            No buy or sell orders recorded.
          <?php endif; ?>
        </div>
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
        <?php fc_render_empty(
            $carrier['shipyard_at'],
            'No ships in stock.',
            'Nothing yet. Upload <code>Shipyard.json</code> while docked at the carrier.',
        ); ?>
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
        <?php fc_render_empty(
            $carrier['outfitting_at'],
            'No modules in stock.',
            'Nothing yet. Upload <code>Outfitting.json</code> while docked at the carrier.',
        ); ?>
      <?php else: ?>
        <p class="muted small"><?= count($rows) ?> module<?= count($rows) === 1 ? '' : 's' ?> stocked.</p>
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

    <?php
    $hooks = fc_all('SELECT * FROM fc_webhooks WHERE carrier_id = :cid ORDER BY id', ['cid' => $carrier['id']]);
    $kinds = fc_webhook_kinds();
    ?>
    <div class="card">
      <h2>Discord</h2>
      <p class="muted small">
        Post this carrier's activity to a Discord channel. Create the webhook in Discord under
        <em>Edit Channel → Integrations → Webhooks</em>, then paste its URL here. The URL is the only
        credential involved, so treat it like a password — anyone holding it can post to that channel.
      </p>

      <?php foreach ($hooks as $hook):
          $on = explode(',', (string) $hook['events']);
          ?>
        <form method="post" class="webhook">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <input type="hidden" name="webhook_id" value="<?= (int) $hook['id'] ?>">

          <div class="webhook-head">
            <div>
              <strong><?= fc_e($hook['label'] ?? 'Webhook') ?></strong>
              <div class="mono small muted"><?= fc_e(fc_webhook_mask((string) $hook['url'])) ?></div>
            </div>
            <div>
              <?php if ((int) $hook['enabled'] === 1): ?>
                <span class="badge on">Active</span>
              <?php else: ?>
                <span class="badge off">Off</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($hook['last_error'] !== null): ?>
            <div class="banner err small">
              Last attempt failed: <?= fc_e($hook['last_error']) ?>
              <?php if ((int) $hook['enabled'] !== 1): ?>
                Fix it in Discord, then tick <em>Active</em> and save to try again.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="label<?= (int) $hook['id'] ?>">Name</label>
            <input id="label<?= (int) $hook['id'] ?>" name="label" type="text" maxlength="64"
                   value="<?= fc_e($hook['label'] ?? '') ?>" placeholder="Squadron channel">
          </div>

          <div class="checks">
            <?php foreach ($kinds as $kind => $spec): ?>
              <div class="check">
                <input type="checkbox" id="k<?= (int) $hook['id'] ?><?= fc_e(str_replace('.', '', $kind)) ?>"
                       name="events[]" value="<?= fc_e($kind) ?>" <?= in_array($kind, $on, true) ? 'checked' : '' ?>>
                <label for="k<?= (int) $hook['id'] ?><?= fc_e(str_replace('.', '', $kind)) ?>">
                  <?= fc_e($spec['label']) ?>
                  <span class="dim small"><?= fc_e($spec['hint']) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="check">
            <input type="checkbox" id="board<?= (int) $hook['id'] ?>" name="board_enabled" <?= (int) $hook['board_enabled'] === 1 ? 'checked' : '' ?>>
            <label for="board<?= (int) $hook['id'] ?>">
              Keep a status board in the channel
              <span class="dim small">One message showing where the carrier is now, edited in place rather than
                reposted. <?= $hook['board_message_id'] === null ? 'Not posted yet.' : 'Posted — pin it so it stays findable.' ?></span>
            </label>
          </div>
          <div class="check">
            <input type="checkbox" id="fin<?= (int) $hook['id'] ?>" name="show_finance" <?= (int) $hook['show_finance'] === 1 ? 'checked' : '' ?>>
            <label for="fin<?= (int) $hook['id'] ?>">
              Include the balance on the status board
              <span class="dim small">Off by default. Anyone who can read the channel would see it.</span>
            </label>
          </div>
          <div class="check">
            <input type="checkbox" id="on<?= (int) $hook['id'] ?>" name="enabled" <?= (int) $hook['enabled'] === 1 ? 'checked' : '' ?>>
            <label for="on<?= (int) $hook['id'] ?>">Active</label>
          </div>

          <div class="actions">
            <button class="btn" type="submit" name="action" value="webhook_save">Save</button>
            <button class="btn ghost" type="submit" name="action" value="webhook_test">Send a test</button>
            <button class="btn danger ghost" type="submit" name="action" value="webhook_delete"
                    onclick="return confirm('Remove this webhook? Messages already posted stay in the channel.')">Remove</button>
          </div>
          <?php if ($hook['last_sent_at'] !== null): ?>
            <p class="small dim" style="margin-bottom:0">Last delivered <?= fc_e(fc_ago($hook['last_sent_at'])) ?>.</p>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>

      <?php if (count($hooks) < 6): ?>
        <form method="post" class="webhook">
          <input type="hidden" name="csrf" value="<?= fc_e(fc_csrf()) ?>">
          <input type="hidden" name="action" value="webhook_add">

          <div class="field">
            <label for="newurl">Webhook URL</label>
            <input id="newurl" name="url" type="url" required
                   placeholder="https://discord.com/api/webhooks/…" autocomplete="off">
          </div>
          <div class="field">
            <label for="newlabel">Name <span class="dim small">optional</span></label>
            <input id="newlabel" name="label" type="text" maxlength="64" placeholder="Squadron channel">
          </div>

          <div class="checks">
            <?php foreach ($kinds as $kind => $spec): ?>
              <div class="check">
                <input type="checkbox" id="new<?= fc_e(str_replace('.', '', $kind)) ?>"
                       name="events[]" value="<?= fc_e($kind) ?>" <?= $spec['default'] ? 'checked' : '' ?>>
                <label for="new<?= fc_e(str_replace('.', '', $kind)) ?>">
                  <?= fc_e($spec['label']) ?>
                  <span class="dim small"><?= fc_e($spec['hint']) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="check">
            <input type="checkbox" id="newboard" name="board_enabled" checked>
            <label for="newboard">Keep a status board in the channel</label>
          </div>

          <div class="actions"><button class="btn" type="submit">Add webhook</button></div>
        </form>
      <?php endif; ?>

      <p class="small dim" style="margin-bottom:0">
        Notices are queued and sent after your upload finishes, so nothing waits on Discord. Journals older than
        six hours are ingested silently — uploading a backlog fills in the history here without announcing
        journeys that ended weeks ago.
      </p>
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
