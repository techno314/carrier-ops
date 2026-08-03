<?php

declare(strict_types=1);

/** Shared page fragments used by the dashboard and the carrier view. */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Tonnes of tritium. A Javelin-Class holds the same 1,000 t as a Drake despite
// being sixty times the hull, so this is not per-hull and does not need to be.
const FC_FUEL_CAPACITY = 1000;

// Light years. Also the same for both hulls, and also not something the
// Companion API reports for either -- the recorded figure only ever comes from
// CarrierStats, which is written when an owner opens the management screen. A
// squadron carrier may never have had one written, so fall back to the hull's
// documented maximum rather than showing nothing.
const FC_JUMP_RANGE_MAX = 500;

function fc_carrier_display_name(array $carrier): string
{
    $name = trim((string) ($carrier['name'] ?? ''));
    return $name !== '' ? $name : ('Carrier ' . ($carrier['callsign'] ?? $carrier['id']));
}

function fc_carrier_link(array $carrier): string
{
    return fc_url('carrier.php?id=' . rawurlencode((string) $carrier['id']));
}

function fc_docking_label(?string $access): string
{
    return match ($access) {
        'all' => 'Open to all',
        'squadron' => 'Squadron only',
        'squadronfriends' => 'Squadron and friends',
        'friends' => 'Friends only',
        'none' => 'Locked down',
        default => 'Unknown',
    };
}

function fc_docking_badge(array $carrier): string
{
    $access = $carrier['docking_access'] ?? null;
    $class = match ($access) {
        'all' => 'on',
        'none' => 'bad',
        null => 'off',
        default => 'warn',
    };
    return '<span class="badge ' . $class . '">' . fc_e(fc_docking_label($access)) . '</span>';
}

/**
 * Mark a squadron carrier as one.
 *
 * Deliberately not the squadron's tag: a Javelin's callsign is its squadron's
 * tag, so printing the tag beside the callsign renders `PDKD PDKD`. The whole
 * squadron name is no better -- it is usually what the carrier is named after.
 * What a reader does not already have from the line is the kind of carrier it
 * is, so that is what this says, with the squadron named on hover.
 */
function fc_squadron_badge(array $carrier): string
{
    if (!fc_is_squadron_carrier($carrier)) {
        return '';
    }
    $name = trim((string) ($carrier['squadron_name'] ?? ''));

    return '<span class="badge on" title="' . fc_e($name !== '' ? $name : 'Squadron carrier') . '">Squadron</span>';
}

function fc_pct(?int $part, ?int $whole): float
{
    if ($part === null || $whole === null || $whole <= 0) {
        return 0.0;
    }
    return max(0.0, min(100.0, ($part / $whole) * 100));
}

/**
 * Header block: name, callsign, where it is, what state it is in.
 *
 * `$linkCallsign` turns the callsign into a link to the carrier's own page.
 * The dashboard wants that; the carrier page itself does not, since a link to
 * where you already are is just a dead end that looks like a way out.
 */
function fc_render_carrier_title(array $carrier, bool $linkCallsign = false): void
{
    $pendingJump = fc_one(
        "SELECT * FROM fc_jumps WHERE carrier_id = :id AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['id' => $carrier['id']],
    );

    // When the carrier arrived is not the same question as when we last heard
    // where it is. `location_at` answers the second: a Companion API payload
    // stamps it with the time of the payload, so a carrier parked for a week
    // would claim to have arrived minutes ago. The open itinerary stop holds
    // the real arrival, and is only trusted when it agrees about the system.
    $arrivedAt = null;
    $here = fc_one(
        'SELECT system, arrival_time FROM fc_itinerary
          WHERE carrier_id = :id AND departure_time IS NULL
          ORDER BY arrival_time DESC LIMIT 1',
        ['id' => $carrier['id']],
    );
    if ($here !== null && $here['system'] === $carrier['system']) {
        $arrivedAt = $here['arrival_time'];
    }
    ?>
    <div class="titlerow">
      <div>
        <h1><?= fc_e(fc_carrier_display_name($carrier)) ?>
          <?php $callsign = $carrier['callsign'] ?? ''; ?>
          <?php if ($callsign !== '' && $linkCallsign): ?>
            <a class="callsign" href="<?= fc_e(fc_carrier_link($carrier)) ?>"
               title="Open <?= fc_e($callsign) ?>"><?= fc_e($callsign) ?></a>
          <?php elseif ($callsign !== ''): ?>
            <span class="callsign"><?= fc_e($callsign) ?></span>
          <?php endif; ?>
          <?= fc_squadron_badge($carrier) ?>
        </h1>
        <p class="muted small" style="margin:0">
          <?php if ($carrier['system'] !== null): ?>
            <?= fc_e($carrier['system']) ?><?php if ($carrier['body'] !== null && $carrier['body'] !== $carrier['system']): ?>
              <span class="dim">· <?= fc_e($carrier['body']) ?></span>
            <?php endif; ?>
            <?php if ($arrivedAt !== null): ?>
              <span class="dim" title="<?= fc_e(fc_dt($arrivedAt)) ?>">· arrived <?= fc_e(fc_ago($arrivedAt)) ?></span>
            <?php else: ?>
              <span class="dim" title="<?= fc_e(fc_dt($carrier['location_at'])) ?>">· position seen <?= fc_e(fc_ago($carrier['location_at'])) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="dim">Location unknown</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="spacer"></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <?= fc_docking_badge($carrier) ?>
        <?php if ((int) $carrier['allow_notorious'] === 1): ?>
          <span class="badge warn">Notorious welcome</span>
        <?php endif; ?>
        <?php if ((int) $carrier['pending_decommission'] === 1): ?>
          <span class="badge bad">Decommissioning</span>
        <?php endif; ?>
        <?php if ($carrier['owner_user_id'] === null): ?>
          <span class="badge off">Unclaimed</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($pendingJump !== null): ?>
      <div class="banner warn">
        <strong>Jump scheduled</strong> — departing for <?= fc_e($pendingJump['system'] ?? 'an unnamed system') ?>
        <?php if ($pendingJump['body'] !== null): ?>(<?= fc_e($pendingJump['body']) ?>)<?php endif; ?>
        at <?= fc_e(fc_dt($pendingJump['departure_time'])) ?>,
        <?= fc_e(fc_ago($pendingJump['departure_time'])) ?>.
      </div>
    <?php endif; ?>
    <?php
}

/** The four headline numbers plus the two capacity bars. */
function fc_render_carrier_stats(array $carrier, bool $showMoney): void
{
    $capacity = (int) ($carrier['capacity'] ?? 0);
    $free = $carrier['space_free'] === null ? null : (int) $carrier['space_free'];
    $used = ($capacity > 0 && $free !== null) ? $capacity - $free : null;
    $fuel = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
    $fuelPct = fc_pct($fuel, FC_FUEL_CAPACITY);
    ?>
    <div class="stats">
      <div class="stat">
        <div class="k">Tritium</div>
        <div class="v"><?= fc_num($fuel) ?> <span class="muted small">/ <?= FC_FUEL_CAPACITY ?> t</span></div>
        <div class="bar <?= $fuelPct < 10 ? 'danger' : ($fuelPct < 25 ? 'warn' : '') ?>"><span style="width:<?= round($fuelPct, 1) ?>%"></span></div>
      </div>
      <?php
      // The current range falls with load and is only ever measured by
      // CarrierStats, so it stays blank until one arrives. The maximum is a
      // property of the hull, so it does not have to wait for anything.
      $rangeMax = $carrier['jump_range_max'] === null
          ? FC_JUMP_RANGE_MAX
          : (float) $carrier['jump_range_max'];
      ?>
      <div class="stat">
        <div class="k">Jump range</div>
        <div class="v"><?= $carrier['jump_range_curr'] === null ? '—' : number_format((float) $carrier['jump_range_curr'], 1) ?> <span class="muted small">ly</span></div>
        <div class="muted small" style="margin-top:6px">max <?= number_format($rangeMax, 0) ?> ly</div>
      </div>
      <div class="stat">
        <div class="k">Cargo space</div>
        <div class="v"><?= fc_num($used) ?> <span class="muted small">/ <?= fc_num($capacity ?: null) ?> t</span></div>
        <div class="bar"><span style="width:<?= round(fc_pct($used, $capacity ?: null), 1) ?>%"></span></div>
      </div>
      <?php if ($showMoney): ?>
        <div class="stat">
          <div class="k">Carrier balance</div>
          <div class="v"><?= fc_cr($carrier['balance'] === null ? null : (int) $carrier['balance']) ?> <span class="muted small">cr</span></div>
          <div class="muted small" style="margin-top:6px">
            <?= fc_cr($carrier['available_balance'] === null ? null : (int) $carrier['available_balance']) ?> available
          </div>
        </div>
      <?php else: ?>
        <div class="stat">
          <div class="k">Docking</div>
          <div class="v sm"><?= fc_e(fc_docking_label($carrier['docking_access'] ?? null)) ?></div>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

/** Space usage split out by what is consuming it. */
function fc_render_space_breakdown(array $carrier): void
{
    $rows = [
        'Cargo' => $carrier['space_cargo'],
        'Reserved for orders' => $carrier['space_reserved'],
        'Crew quarters and services' => $carrier['space_crew'],
        'Ship packs' => $carrier['space_shippacks'],
        'Module packs' => $carrier['space_modulepacks'],
        'Free' => $carrier['space_free'],
    ];
    if (array_filter($rows, static fn($v) => $v !== null) === []) {
        return;
    }
    ?>
    <div class="card">
      <h2>Space usage</h2>
      <div class="tablewrap">
        <table>
          <tbody>
          <?php foreach ($rows as $label => $value): ?>
            <tr>
              <td><?= fc_e($label) ?></td>
              <td class="num"><?= fc_num($value === null ? null : (int) $value) ?> t</td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td><strong>Total capacity</strong></td>
            <td class="num"><strong><?= fc_num($carrier['capacity'] === null ? null : (int) $carrier['capacity']) ?> t</strong></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php
}

/**
 * Crew, upkeep and the solvency forecast.
 *
 * This is the part the Companion API would hand over ready-made; here it is
 * rebuilt from the crew roster and the published cost table, so it is an
 * estimate and says so.
 */
function fc_render_upkeep(array $carrier, array $crew): void
{
    $upkeep = fc_upkeep($crew, $carrier);
    $lastTick = fc_last_upkeep_tick();
    $jumps = (int) (fc_one(
        'SELECT COUNT(*) AS n FROM fc_itinerary WHERE carrier_id = :id AND arrival_time >= :since',
        ['id' => $carrier['id'], 'since' => gmdate('Y-m-d H:i:s', $lastTick)],
    )['n'] ?? 0);

    $balance = $carrier['balance'] === null ? null : (int) $carrier['balance'];
    $solvency = fc_solvency($upkeep, $balance, $jumps);
    ?>
    <div class="card">
      <h2>Upkeep
        <?php if ($upkeep['exact']): ?>
          <span class="badge on">from the game</span>
        <?php else: ?>
          <span class="badge">estimated</span>
        <?php endif; ?>
      </h2>

      <div class="stats" style="margin-bottom:16px">
        <div class="stat">
          <div class="k">Weekly upkeep</div>
          <div class="v"><?= fc_cr($upkeep['total']) ?> <span class="muted small">cr</span></div>
          <div class="muted small" style="margin-top:6px">core <?= fc_cr($upkeep['core']) ?> + services <?= fc_cr($upkeep['services']) ?></div>
        </div>
        <div class="stat">
          <div class="k">Next charge</div>
          <div class="v sm"><?= fc_e(gmdate('D j M, H:i', fc_next_upkeep_tick())) ?> UTC</div>
          <div class="muted small" style="margin-top:6px"><?= fc_e(fc_duration(max(0, fc_next_upkeep_tick() - time()))) ?> away</div>
        </div>
        <div class="stat">
          <div class="k">Jump fees this week</div>
          <div class="v"><?= fc_cr($solvency['jump_fees']) ?> <span class="muted small">cr</span></div>
          <div class="muted small" style="margin-top:6px"><?= $jumps ?> jump<?= $jumps === 1 ? '' : 's' ?> since <?= fc_e(gmdate('D j M', $lastTick)) ?></div>
        </div>
        <div class="stat">
          <div class="k">Solvent for</div>
          <?php if ($solvency['weeks'] === null): ?>
            <div class="v sm muted">Unknown</div>
            <div class="muted small" style="margin-top:6px">No balance recorded yet</div>
          <?php elseif ($solvency['weeks'] === 0): ?>
            <div class="v" style="color:var(--danger)">Under one week</div>
            <div class="muted small" style="margin-top:6px">Top up before <?= fc_e(gmdate('D j M', (int) $solvency['broke_at'])) ?></div>
          <?php else: ?>
            <?php $span = fc_weeks_span($solvency['weeks']); ?>
            <div class="v">
              <?= $solvency['weeks'] ?> week<?= $solvency['weeks'] === 1 ? '' : 's' ?>
              <?php if ($span !== null): ?>
                <span class="muted small">· <?= fc_e($span) ?></span>
              <?php endif; ?>
            </div>
            <div class="muted small" style="margin-top:6px">runs dry around <?= fc_e(gmdate('j M Y', (int) $solvency['broke_at'])) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($upkeep['lines'] === []): ?>
        <div class="empty">No optional services installed, or no <code>CarrierStats</code> uploaded yet.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <thead>
            <tr>
              <th>Service</th>
              <th>Officer</th>
              <th>State</th>
              <th class="num">Weekly</th>
              <th class="num">Space</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($upkeep['lines'] as $line): ?>
              <tr>
                <td><?= fc_e($line['label']) ?></td>
                <td class="muted"><?= fc_e($line['name'] ?? $line['officer']) ?></td>
                <td>
                  <?php if ($line['running']): ?>
                    <span class="badge on">Active</span>
                  <?php else: ?>
                    <span class="badge off">Suspended</span>
                  <?php endif; ?>
                </td>
                <td class="num"><?= fc_cr($line['cost']) ?></td>
                <td class="num muted"><?= fc_num($line['cargo']) ?> t</td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="3"><strong>Core services</strong> <span class="muted small">bridge, commodities, tritium depot</span></td>
              <td class="num"><strong><?= fc_cr($upkeep['core']) ?></strong></td>
              <td></td>
            </tr>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <p class="small dim" style="margin-bottom:0">
        <?php if ($upkeep['exact']): ?>
          The core and services totals are the game's own figures, by way of the Companion API. The per-service
          breakdown above is still derived from the published cost table, since only the two totals come back.
          A suspended service still costs its retainer; only selling it removes the charge entirely.
        <?php else: ?>
          The journal records which crew are aboard and whether each service is running, but not what the game charges
          for them, so upkeep is reconstructed from the published cost table. A suspended service still costs its
          retainer; only selling it removes the charge entirely.
          <a href="<?= fc_e(fc_url('plugin.php')) ?>">The EDMC plugin</a> can supply the exact figures.
        <?php endif; ?>
      </p>
    </div>
    <?php
}

/** Per-service tax rates, when the journal has given us any. */
function fc_render_taxes(array $carrier): void
{
    $taxes = [
        'Refuel' => $carrier['tax_refuel'],
        'Repair' => $carrier['tax_repair'],
        'Armoury' => $carrier['tax_rearm'],
        'Shipyard' => $carrier['tax_shipyard'],
        'Outfitting' => $carrier['tax_outfitting'],
    ];
    $any = array_filter($taxes, static fn($v) => $v !== null) !== [];
    if (!$any && $carrier['tax_rate'] === null) {
        return;
    }
    ?>
    <div class="card">
      <h2>Tariffs</h2>
      <?php if (!$any): ?>
        <p class="muted">Flat rate of <strong><?= (int) $carrier['tax_rate'] ?>%</strong> on all services.</p>
      <?php else: ?>
        <div class="tablewrap">
          <table>
            <tbody>
            <?php foreach ($taxes as $label => $rate): ?>
              <tr>
                <td><?= fc_e($label) ?></td>
                <td class="num"><?= $rate === null ? '—' : (int) $rate . '%' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="small dim" style="margin-bottom:0">Reserve set aside for upkeep:
        <strong><?= $carrier['reserve_percent'] === null ? '—' : (int) $carrier['reserve_percent'] . '%' ?></strong>
        (<?= fc_cr($carrier['reserve_balance'] === null ? null : (int) $carrier['reserve_balance']) ?> cr)</p>
    </div>
    <?php
}

/**
 * The empty state for a list that has a "last fetched" timestamp.
 *
 * An empty table means two entirely different things depending on whether we
 * have ever read that part of the carrier. Saying "upload the file" to someone
 * whose shipyard is simply empty sends them to do something pointless, and
 * saying "nothing stocked" when we have never looked is a claim we cannot
 * make.
 */
function fc_render_empty(?string $fetchedAt, string $emptyMessage, string $neverMessage): void
{
    echo '<div class="empty">' . ($fetchedAt === null ? $neverMessage : fc_e($emptyMessage)) . '</div>';
}

/** A short row in a list of carriers. */
function fc_render_carrier_row(array $carrier): void
{
    ?>
    <tr>
      <td>
        <a href="<?= fc_e(fc_carrier_link($carrier)) ?>"><?= fc_e(fc_carrier_display_name($carrier)) ?></a>
        <div class="callsign small"><?= fc_e($carrier['callsign'] ?? '—') ?></div>
      </td>
      <td><?= fc_e($carrier['system'] ?? '—') ?></td>
      <td class="num"><?= fc_num($carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level']) ?> t</td>
      <td><?= fc_docking_badge($carrier) ?></td>
      <td class="muted small nowrap"><?= fc_e(fc_ago($carrier['updated_at'])) ?></td>
    </tr>
    <?php
}

/**
 * The carrier's balance over time, as an inline SVG.
 *
 * Drawn on the server rather than by a charting library. This app has no
 * JavaScript dependencies and no build step, and a line through a dozen points
 * is not a reason to acquire either; an SVG needs nothing at the far end and
 * prints, scales and reads with JavaScript off.
 *
 * Only entries that recorded a balance are plotted. A fuel delivery or a pack
 * purchase says what it cost but not what was left afterwards, and joining
 * those to the line would draw a fall that never happened.
 */
function fc_render_balance_chart(array $carrier): void
{
    $rows = fc_all(
        "SELECT ts, balance FROM fc_ledger
          WHERE carrier_id = :id AND unit = 'cr' AND balance IS NOT NULL
          ORDER BY ts ASC",
        ['id' => $carrier['id']],
    );

    // One point is not a line. Two is the least that says anything.
    if (count($rows) < 2) {
        return;
    }

    $points = [];
    foreach ($rows as $row) {
        $points[] = [
            'at' => (int) strtotime((string) $row['ts'] . ' UTC'),
            'value' => (int) $row['balance'],
        ];
    }

    // The balance as it stands belongs on the end, otherwise the line stops at
    // the last transaction the journal happened to record a balance for.
    $latest = $carrier['balance'] === null ? null : (int) $carrier['balance'];
    if ($latest !== null && $latest !== end($points)['value']) {
        $points[] = ['at' => time(), 'value' => $latest];
    }

    $values = array_column($points, 'value');
    $times = array_column($points, 'at');
    $min = min($values);
    $max = max($values);
    $from = min($times);
    $to = max($times);

    // A flat line would divide by zero, and a carrier whose balance never moved
    // still deserves a chart rather than a crash.
    $span = max(1, $to - $from);
    $range = max(1, $max - $min);

    // Padded so the extremes are not drawn on the frame itself.
    $w = 1000.0;
    $h = 220.0;
    $padX = 8.0;
    $padY = 14.0;

    $coords = [];
    foreach ($points as $point) {
        $x = $padX + ($point['at'] - $from) / $span * ($w - 2 * $padX);
        $y = $h - $padY - ($point['value'] - $min) / $range * ($h - 2 * $padY);
        $coords[] = [round($x, 2), round($y, 2), $point];
    }

    $line = [];
    foreach ($coords as $c) {
        $line[] = $c[0] . ',' . $c[1];
    }
    $area = $coords[0][0] . ',' . ($h - $padY) . ' '
        . implode(' ', $line) . ' '
        . end($coords)[0] . ',' . ($h - $padY);

    $change = end($values) - $values[0];
    ?>
    <div class="card">
      <h2>Balance over time
        <span class="muted small"><?= count($points) ?> points · <?= fc_e(fc_dt(gmdate('Y-m-d H:i:s', $from))) ?> onwards</span>
      </h2>

      <div class="chart">
        <svg viewBox="0 0 <?= (int) $w ?> <?= (int) $h ?>" preserveAspectRatio="none" role="img"
             aria-label="Carrier balance from <?= fc_e(fc_cr($values[0])) ?> to <?= fc_e(fc_cr(end($values))) ?> credits">
          <defs>
            <linearGradient id="fcchart" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="var(--accent)" stop-opacity="0.35"/>
              <stop offset="1" stop-color="var(--accent)" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <polygon points="<?= fc_e($area) ?>" fill="url(#fcchart)"/>
          <polyline points="<?= fc_e(implode(' ', $line)) ?>" fill="none" stroke="var(--accent)"
                    stroke-width="2" vector-effect="non-scaling-stroke"
                    stroke-linejoin="round" stroke-linecap="round"/>
          <?php foreach ($coords as $c): ?>
            <circle cx="<?= $c[0] ?>" cy="<?= $c[1] ?>" r="3" fill="var(--accent)" vector-effect="non-scaling-stroke">
              <title><?= fc_e(fc_dt(gmdate('Y-m-d H:i:s', $c[2]['at'])) . ' — ' . fc_cr($c[2]['value']) . ' cr') ?></title>
            </circle>
          <?php endforeach; ?>
        </svg>
      </div>

      <div class="stats" style="margin-top:14px">
        <div class="stat">
          <div class="k">Lowest</div>
          <div class="v sm"><?= fc_cr($min) ?> <span class="muted small">cr</span></div>
        </div>
        <div class="stat">
          <div class="k">Highest</div>
          <div class="v sm"><?= fc_cr($max) ?> <span class="muted small">cr</span></div>
        </div>
        <div class="stat">
          <div class="k">Change over the period</div>
          <div class="v sm" style="color:<?= $change >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">
            <?= ($change >= 0 ? '+' : '') . fc_cr($change) ?> <span class="muted small">cr</span>
          </div>
        </div>
      </div>

      <p class="small dim" style="margin-bottom:0">
        Only transactions that reported a balance are plotted — bank transfers do, tritium deliveries and pack
        purchases do not, so the line follows what the game actually stated rather than guessing between them.
      </p>
    </div>
    <?php
}

/**
 * Whether a carrier wants looking at, and why.
 *
 * The point of a fleet view is not to show every carrier equally -- it is to
 * make the one in trouble impossible to miss among the ones that are fine.
 *
 * @return string[] short reasons, empty when nothing is wrong
 */
/**
 * How long this carrier can pay for itself, worked out once per request.
 *
 * Memoised because a fleet view asks the same question of the same carrier
 * more than once -- for its warnings and for its card -- and each answer
 * otherwise costs a crew lookup and a jump count.
 *
 * Jump fees are included, which the warnings did not previously do. The
 * detail page has always counted them, so a carrier could be described as
 * solvent for one number of weeks on its own page and another on the fleet
 * view. Counting them everywhere is both the more accurate figure and the
 * only way the two agree.
 *
 * @return array{weekly:int,weeks:?int,broke_at:?int,jump_fees:int}
 */
function fc_carrier_solvency(array $carrier): array
{
    static $cache = [];
    $id = (int) $carrier['id'];
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $crew = fc_all('SELECT * FROM fc_crew WHERE carrier_id = :id', ['id' => $id]);
    $lastTick = fc_last_upkeep_tick();
    $jumps = (int) (fc_one(
        'SELECT COUNT(*) AS n FROM fc_itinerary WHERE carrier_id = :id AND arrival_time >= :since',
        ['id' => $id, 'since' => gmdate('Y-m-d H:i:s', $lastTick)],
    )['n'] ?? 0);

    return $cache[$id] = fc_solvency(
        fc_upkeep($crew, $carrier),
        $carrier['balance'] === null ? null : (int) $carrier['balance'],
        $jumps,
    );
}

function fc_carrier_warnings(array $carrier): array
{
    $out = [];

    if ((int) ($carrier['pending_decommission'] ?? 0) === 1) {
        $out[] = 'Decommission scheduled';
    }

    $fuel = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
    if ($fuel !== null && $fuel <= 150) {
        $out[] = 'Tritium low';
    }

    if ($carrier['balance'] !== null) {
        $solvency = fc_carrier_solvency($carrier);
        if ($solvency['weeks'] !== null && $solvency['weeks'] < 2) {
            $out[] = $solvency['weeks'] < 1 ? 'Upkeep not covered' : 'Upkeep covered for under two weeks';
        }
    }

    return $out;
}

/**
 * One carrier, compactly, for a dashboard showing several.
 *
 * Deliberately not fc_render_carrier_stats: with one carrier the full spread of
 * figures is the page, but repeated six times it is a wall, and the question a
 * fleet view answers is "which of these needs me" rather than "what is the jump
 * range of each".
 */
function fc_render_carrier_card(array $carrier): void
{
    $fuel = $carrier['fuel_level'] === null ? null : (int) $carrier['fuel_level'];
    $fuelPct = fc_pct($fuel, FC_FUEL_CAPACITY);
    $warnings = fc_carrier_warnings($carrier);

    $next = fc_one(
        "SELECT system, departure_time FROM fc_jumps
          WHERE carrier_id = :cid AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['cid' => $carrier['id']],
    );
    ?>
    <div class="card carriercard<?= $warnings === [] ? '' : ' needsattention' ?>">
      <h2 style="margin-bottom:6px">
        <a href="<?= fc_e(fc_carrier_link($carrier)) ?>"><?= fc_e(fc_carrier_display_name($carrier)) ?></a>
        <span class="callsign small"><?= fc_e($carrier['callsign'] ?? '—') ?></span>
        <?= fc_squadron_badge($carrier) ?>
      </h2>

      <p class="muted small" style="margin:0 0 12px">
        <?= fc_e($carrier['system'] ?? 'Position unknown') ?>
        <?php if (($carrier['body'] ?? null) !== null && $carrier['body'] !== ''): ?>
          · <?= fc_e($carrier['body']) ?>
        <?php endif; ?>
        · <?= fc_docking_badge($carrier) ?>
      </p>

      <?php if ($warnings !== []): ?>
        <div class="banner warn small" style="margin-bottom:12px">
          <?= fc_e(implode(' · ', $warnings)) ?>
        </div>
      <?php endif; ?>

      <div class="stats">
        <div class="stat">
          <div class="k">Tritium</div>
          <div class="v sm"><?= fc_num($fuel) ?> <span class="muted small">/ <?= FC_FUEL_CAPACITY ?> t</span></div>
          <div class="bar <?= $fuelPct < 10 ? 'danger' : ($fuelPct < 25 ? 'warn' : '') ?>"><span style="width:<?= round($fuelPct, 1) ?>%"></span></div>
        </div>
        <div class="stat">
          <div class="k">Free space</div>
          <div class="v sm"><?= $carrier['space_free'] === null ? '—' : fc_num((int) $carrier['space_free']) ?> <span class="muted small">t</span></div>
        </div>
        <div class="stat">
          <div class="k"><?= $next === null ? 'Next jump' : 'Jumping to' ?></div>
          <div class="v sm">
            <?php if ($next === null): ?>
              <span class="muted">None plotted</span>
            <?php else: ?>
              <?= fc_e($next['system'] ?? '?') ?>
              <div class="muted small" style="margin-top:4px"><?= fc_e(fc_ago($next['departure_time'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php
        // The same figure the carrier's own page gives, from the same helper,
        // because a fleet view exists to spot the one that needs money and a
        // number that disagreed with the detail page would be worse than none.
        $solvency = fc_carrier_solvency($carrier);
        ?>
        <div class="stat">
          <div class="k">Solvent for</div>
          <?php if ($solvency['weeks'] === null): ?>
            <div class="v sm muted">Unknown</div>
          <?php elseif ($solvency['weeks'] === 0): ?>
            <div class="v sm" style="color:var(--danger)">Under a week</div>
            <div class="muted small" style="margin-top:4px">
              by <?= fc_e(gmdate('j M', (int) $solvency['broke_at'])) ?>
            </div>
          <?php else: ?>
            <div class="v sm"<?= $solvency['weeks'] < 4 ? ' style="color:var(--warn)"' : '' ?>>
              <?= $solvency['weeks'] ?> week<?= $solvency['weeks'] === 1 ? '' : 's' ?>
            </div>
            <div class="muted small" style="margin-top:4px">
              to <?= fc_e(gmdate('j M', (int) $solvency['broke_at'])) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="actions">
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>">Overview</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=finance">Finance</a>
        <a class="btn ghost sm" href="<?= fc_e(fc_carrier_link($carrier)) ?>&amp;tab=market">Market</a>
      </div>
    </div>
    <?php
}

/**
 * The fleet at a glance, above the individual carriers.
 *
 * Only drawn for more than one, because a summary of a single carrier is the
 * carrier written twice.
 */
function fc_render_fleet_summary(array $carriers): void
{
    if (count($carriers) < 2) {
        return;
    }

    $balance = 0;
    $haveBalance = false;
    $free = 0;
    $haveFree = false;
    $attention = 0;

    foreach ($carriers as $carrier) {
        if ($carrier['balance'] !== null) {
            $balance += (int) $carrier['balance'];
            $haveBalance = true;
        }
        if ($carrier['space_free'] !== null) {
            $free += (int) $carrier['space_free'];
            $haveFree = true;
        }
        if (fc_carrier_warnings($carrier) !== []) {
            $attention++;
        }
    }
    ?>
    <div class="stats" style="margin-bottom:18px">
      <div class="stat">
        <div class="k">Carriers</div>
        <div class="v"><?= count($carriers) ?></div>
      </div>
      <div class="stat">
        <div class="k">Combined balance</div>
        <div class="v sm"><?= $haveBalance ? fc_cr($balance) : '—' ?> <span class="muted small">cr</span></div>
      </div>
      <div class="stat">
        <div class="k">Free space</div>
        <div class="v sm"><?= $haveFree ? fc_num($free) : '—' ?> <span class="muted small">t</span></div>
      </div>
      <div class="stat">
        <div class="k">Needing attention</div>
        <div class="v" style="<?= $attention > 0 ? 'color:var(--warn)' : '' ?>"><?= $attention ?></div>
      </div>
    </div>
    <?php
}
