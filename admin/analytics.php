<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Analytics.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

$presets = [7, 14, 30, 60, 90];
$today   = date('Y-m-d');

// Custom range takes priority when both from/to are present and valid
$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to']   ?? '');
$days    = (int)($_GET['days'] ?? 0);

$isCustom = false;
if ($fromRaw && $toRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) && $fromRaw <= $toRaw) {
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

adminLayout('Analytics', function() use ($data, $days, $presets, $from, $to, $isCustom, $today) {
?>

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
    <input type="date" name="from" value="<?= h($isCustom ? $from : '') ?>"
           max="<?= $today ?>"
           style="padding:4px 8px; border:1px solid <?= $isCustom ? 'var(--brown)' : '#ccc' ?>; border-radius:4px; font-size:13px; color:#333;">
    <span style="color:#888; font-size:13px;">to</span>
    <input type="date" name="to" value="<?= h($isCustom ? $to : '') ?>"
           max="<?= $today ?>"
           style="padding:4px 8px; border:1px solid <?= $isCustom ? 'var(--brown)' : '#ccc' ?>; border-radius:4px; font-size:13px; color:#333;">
    <button type="submit" class="btn btn-sm <?= $isCustom ? 'btn-primary' : 'btn-secondary' ?>">Go</button>
  </form>

  <span style="margin-left:auto; font-size:12px; color:#888; white-space:nowrap;">
    <?= date('M j, Y', strtotime($from)) ?> &ndash; <?= date('M j, Y', strtotime($to)) ?>
  </span>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="num"><?= number_format($data['total']['views'] ?? 0) ?></div>
    <div class="label">Total Views</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($data['total']['visitors'] ?? 0) ?></div>
    <div class="label">Unique Visitors</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Views Over Time</h2></div>
    <div class="chart-container">
      <canvas id="viewsChart"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Device Breakdown</h2></div>
    <div class="chart-container">
      <canvas id="deviceChart"></canvas>
    </div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Top Pages</h2></div>
    <table class="data-table">
      <thead><tr><th>Page</th><th>Views</th></tr></thead>
      <tbody>
        <?php if (empty($data['topPages'])): ?>
          <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px;">No data yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['topPages'] as $p): ?>
          <tr>
            <td style="font-size:12px;">
              <a href="<?= siteUrl(ltrim($p['url'], '/')) ?>" target="_blank"
                 style="color:var(--brown);"><?= h($p['url']) ?></a>
            </td>
            <td><?= number_format($p['views']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-header"><h2 class="card-title">Top Referrers</h2></div>
    <table class="data-table">
      <thead><tr><th>Source</th><th>Views</th></tr></thead>
      <tbody>
        <?php if (empty($data['referrers'])): ?>
          <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px;">No referral data yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['referrers'] as $r): ?>
          <tr>
            <td style="font-size:12px; word-break:break-all;"><?= h($r['referrer'] ?? 'Direct') ?></td>
            <td><?= number_format($r['views']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const days = <?= json_encode($data['byDay']) ?>;
  new Chart(document.getElementById('viewsChart'), {
    type: 'bar',
    data: {
      labels: days.map(d => d.day),
      datasets: [{
        label: 'Views',
        data: days.map(d => d.views),
        backgroundColor: 'rgba(107,66,38,.7)',
      },{
        label: 'Visitors',
        data: days.map(d => d.visitors),
        backgroundColor: 'rgba(196,154,108,.7)',
      }]
    },
    options: { responsive:true, maintainAspectRatio:false,
      scales: { y: { beginAtZero:true } },
      plugins: { legend: { position:'top' } }
    }
  });

  const devices = <?= json_encode($data['byDevice']) ?>;
  new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
      labels: devices.map(d => d.device),
      datasets: [{
        data: devices.map(d => d.views),
        backgroundColor: ['#6b4226','#c49a6c','#e8d9c4'],
      }]
    },
    options: { responsive:true, maintainAspectRatio:false,
      plugins: { legend: { position:'bottom' } }
    }
  });
});
</script>

<?php }); ?>
