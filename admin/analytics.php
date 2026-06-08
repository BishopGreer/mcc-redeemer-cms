<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Analytics.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requireRole('author');

$presets = [7, 14, 30, 60, 90];
$today   = date('Y-m-d');

$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to']   ?? '');
$days    = (int)($_GET['days'] ?? 0);

$isCustom = false;
if ($fromRaw && $toRaw
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)
    && $fromRaw <= $toRaw) {
    $from     = $fromRaw;
    $to       = min($toRaw, $today);
    $isCustom = true;
    $days     = 0;
} else {
    $days = in_array($days, $presets) ? $days : 30;
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $to   = $today;
}

$data = Analytics::summary($from, $to);

// Fill in all 24 hours for the hourly chart
$hourlyFull = array_fill(0, 24, 0);
foreach ($data['byHour'] as $h) { $hourlyFull[(int)$h['hour']] = (int)$h['views']; }

adminLayout('Analytics', function() use ($data, $days, $presets, $from, $to, $isCustom, $today, $hourlyFull) {

$primary  = '#6B3FA0';
$accent   = '#D4A017';
$muted    = '#8B5CB8';
?>

<!-- Date range picker -->
<div style="display:flex; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
  <strong style="white-space:nowrap;">Report period:</strong>
  <?php foreach ($presets as $d): ?>
    <a href="<?= siteUrl('admin/analytics?days=' . $d) ?>"
       class="btn btn-sm <?= (!$isCustom && $d === $days) ? 'btn-primary' : 'btn-secondary' ?>">
      <?= $d ?> days
    </a>
  <?php endforeach; ?>
  <span style="color:#ccc; margin:0 4px;">|</span>
  <form method="get" action="<?= siteUrl('admin/analytics') ?>"
        style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
    <input type="date" name="from" value="<?= h($isCustom ? $from : '') ?>" max="<?= $today ?>"
           style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
    <span style="color:#888; font-size:13px;">to</span>
    <input type="date" name="to" value="<?= h($isCustom ? $to : '') ?>" max="<?= $today ?>"
           style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
    <button type="submit" class="btn btn-sm <?= $isCustom ? 'btn-primary' : 'btn-secondary' ?>">Go</button>
  </form>
  <span style="margin-left:auto; font-size:12px; color:#888; white-space:nowrap;">
    <?= date('M j, Y', strtotime($from)) ?> &ndash; <?= date('M j, Y', strtotime($to)) ?>
  </span>
</div>

<!-- Key metrics -->
<?php
$avgDurSec = (int)($data['avgDuration']['avg_dur'] ?? 0);
$avgDurFmt = $avgDurSec > 0
    ? ($avgDurSec >= 60 ? floor($avgDurSec/60) . 'm ' . ($avgDurSec%60) . 's' : $avgDurSec . 's')
    : '—';
?>
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); margin-bottom:20px;">
  <div class="stat-card">
    <div class="num"><?= number_format($data['total']['views'] ?? 0) ?></div>
    <div class="label">Total Views</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($data['total']['visitors'] ?? 0) ?></div>
    <div class="label">Unique Visitors</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($data['total']['sessions'] ?? 0) ?></div>
    <div class="label">Sessions</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= $data['bounceRate'] ?? 0 ?>%</div>
    <div class="label">Bounce Rate</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= $avgDurFmt ?></div>
    <div class="label">Avg. Duration</div>
  </div>
</div>

<!-- Traffic over time + Device breakdown -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Traffic Over Time</h2></div>
    <div class="chart-container" style="height:240px;">
      <canvas id="viewsChart"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Devices</h2></div>
    <div class="chart-container" style="height:240px;">
      <canvas id="deviceChart"></canvas>
    </div>
  </div>
</div>

<!-- Browser + OS -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Browsers</h2></div>
    <div class="chart-container" style="height:200px;">
      <canvas id="browserChart"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Operating Systems</h2></div>
    <div class="chart-container" style="height:200px;">
      <canvas id="osChart"></canvas>
    </div>
  </div>
</div>

<!-- Hourly distribution -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <h2 class="card-title">Hourly Traffic Distribution</h2>
    <span style="font-size:12px; color:#888;">When visitors are most active (site timezone)</span>
  </div>
  <div class="chart-container" style="height:180px;">
    <canvas id="hourlyChart"></canvas>
  </div>
</div>

<!-- Top pages + Entry pages -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Top Pages</h2></div>
    <table class="data-table">
      <thead><tr><th>Page</th><th style="text-align:right;">Views</th></tr></thead>
      <tbody>
        <?php if (empty($data['topPages'])): ?>
          <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px;">No data yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['topPages'] as $p): ?>
          <tr>
            <td style="font-size:12px; word-break:break-all;">
              <a href="<?= siteUrl(ltrim($p['url'], '/')) ?>" target="_blank"
                 style="color:<?= $primary ?>;"><?= h($p['url']) ?></a>
            </td>
            <td style="text-align:right;"><?= number_format($p['views']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Entry Pages</h2>
      <span style="font-size:12px; color:#888;">First page visitors land on</span>
    </div>
    <table class="data-table">
      <thead><tr><th>Page</th><th style="text-align:right;">Entries</th></tr></thead>
      <tbody>
        <?php if (empty($data['entryPages'])): ?>
          <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px;">No session data yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['entryPages'] as $p): ?>
          <tr>
            <td style="font-size:12px; word-break:break-all;">
              <a href="<?= siteUrl(ltrim($p['url'], '/')) ?>" target="_blank"
                 style="color:<?= $primary ?>;"><?= h($p['url']) ?></a>
            </td>
            <td style="text-align:right;"><?= number_format($p['entries']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Referrer domains -->
<div class="card">
  <div class="card-header"><h2 class="card-title">Traffic Sources</h2></div>
  <table class="data-table">
    <thead><tr><th>Source</th><th style="text-align:right;">Views</th><th>Share</th></tr></thead>
    <tbody>
      <?php if (empty($data['referrers'])): ?>
        <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px;">No referral data yet.</td></tr>
      <?php endif; ?>
      <?php
      $totalViews = $data['total']['views'] ?? 0;
      foreach ($data['referrers'] as $r):
        $pct = $totalViews > 0 ? round($r['views'] / $totalViews * 100, 1) : 0;
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($r['referrer_domain'] ?? 'Direct') ?></td>
          <td style="text-align:right;"><?= number_format($r['views']) ?></td>
          <td style="min-width:120px;">
            <div style="display:flex; align-items:center; gap:6px;">
              <div style="flex:1; background:#f3f4f6; border-radius:4px; height:8px;">
                <div style="width:<?= min($pct*3, 100) ?>%; background:<?= $primary ?>; height:8px; border-radius:4px;"></div>
              </div>
              <span style="font-size:11px; color:#6b7280; min-width:32px;"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script nonce="<?= cspNonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
  const primary = '<?= $primary ?>';
  const accent  = '<?= $accent ?>';

  // ── Traffic over time ──────────────────────────────────────────────────────
  const dayData = <?= json_encode($data['byDay']) ?>;
  new Chart(document.getElementById('viewsChart'), {
    type: 'bar',
    data: {
      labels: dayData.map(d => d.day),
      datasets: [
        { label: 'Views',    data: dayData.map(d => d.views),    backgroundColor: primary + 'CC' },
        { label: 'Visitors', data: dayData.map(d => d.visitors), backgroundColor: accent  + 'CC' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      plugins: { legend: { position: 'top' } }
    }
  });

  // ── Devices ────────────────────────────────────────────────────────────────
  const deviceData = <?= json_encode($data['byDevice']) ?>;
  new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
      labels: deviceData.map(d => d.device),
      datasets: [{ data: deviceData.map(d => d.views),
        backgroundColor: ['#6B3FA0','#D4A017','#8B5CB8','#F0C040'] }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } } }
  });

  // ── Browsers ───────────────────────────────────────────────────────────────
  const browserData = <?= json_encode($data['byBrowser']) ?>;
  const browserColors = ['#4285F4','#FF6B35','#34A853','#EA4335','#7C3AED','#0EA5E9','#F59E0B','#6B7280'];
  new Chart(document.getElementById('browserChart'), {
    type: 'bar',
    data: {
      labels: browserData.map(d => d.browser),
      datasets: [{ label: 'Views', data: browserData.map(d => d.views),
        backgroundColor: browserData.map((_, i) => browserColors[i % browserColors.length]) }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  // ── Operating Systems ──────────────────────────────────────────────────────
  const osData = <?= json_encode($data['byOs']) ?>;
  const osColors = ['#6B3FA0','#D4A017','#34A853','#4285F4','#EA4335','#F59E0B','#8B5CB8','#6B7280'];
  new Chart(document.getElementById('osChart'), {
    type: 'bar',
    data: {
      labels: osData.map(d => d.os),
      datasets: [{ label: 'Views', data: osData.map(d => d.views),
        backgroundColor: osData.map((_, i) => osColors[i % osColors.length]) }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  // ── Hourly distribution ────────────────────────────────────────────────────
  const hourlyData = <?= json_encode(array_values($hourlyFull)) ?>;
  const hourLabels = Array.from({length:24}, (_, i) => {
    const ampm = i < 12 ? 'am' : 'pm';
    return (i === 0 ? 12 : i > 12 ? i - 12 : i) + ampm;
  });
  new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
      labels: hourLabels,
      datasets: [{ label: 'Views', data: hourlyData,
        backgroundColor: primary + '99',
        borderColor: primary,
        borderWidth: 1 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });
});
</script>
<?php
});
