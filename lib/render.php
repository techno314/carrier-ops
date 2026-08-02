<?php

declare(strict_types=1);

/** Shared page fragments used by the dashboard and the carrier view. */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const FC_FUEL_CAPACITY = 1000;   // tonnes of tritium

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

function fc_pct(?int $part, ?int $whole): float
{
    if ($part === null || $whole === null || $whole <= 0) {
        return 0.0;
    }
    return max(0.0, min(100.0, ($part / $whole) * 100));
}

/** Header block: name, callsign, where it is, what state it is in. */
function fc_render_carrier_title(array $carrier, ?array $viewer = null): void
{
    $pendingJump = fc_one(
        "SELECT * FROM fc_jumps WHERE carrier_id = :id AND status = 'scheduled' AND departure_time > UTC_TIMESTAMP()
          ORDER BY departure_time ASC LIMIT 1",
        ['id' => $carrier['id']],
    );
    ?>
    <div class="titlerow">
      <div>
        <h1><?= fc_e(fc_carrier_display_name($carrier)) ?>
          <span class="callsign"><?= fc_e($carrier['callsign'] ?? '') ?></span>
        </h1>
        <p class="muted small" style="margin:0">
          <?php if ($carrier['system'] !== null): ?>
            <?= fc_e($carrier['system']) ?><?php if ($carrier['body'] !== null && $carrier['body'] !== $carrier['system']): ?>
              <span class="dim">· <?= fc_e($carrier['body']) ?></span>
            <?php endif; ?>
            <span class="dim">· arrived <?= fc_e(fc_ago($carrier['location_at'])) ?></span>
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
      <div class="stat">
        <div class="k">Jump range</div>
        <div class="v"><?= $carrier['jump_range_curr'] === null ? '—' : number_format((float) $carrier['jump_range_curr'], 1) ?> <span class="muted small">ly</span></div>
        <div class="muted small" style="margin-top:6px">max <?= $carrier['jump_range_max'] === null ? '—' : number_format((float) $carrier['jump_range_max'], 0) ?> ly</div>
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
