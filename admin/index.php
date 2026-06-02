<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Analytics.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

// Domain mismatch notice — shown when testing on a different domain than the canonical one
$storedDomain   = Database::setting('site_url', '');
$activeDomain   = SITE_URL;
$domainMismatch = $storedDomain && rtrim($storedDomain, '/') !== rtrim($activeDomain, '/');

$pageCount       = Database::fetch("SELECT COUNT(*) c FROM pages WHERE status='published' AND site_id = ?", [Database::siteId()])['c'];
$postCount       = Database::fetch("SELECT COUNT(*) c FROM posts WHERE status='published' AND site_id = ?", [Database::siteId()])['c'];
$mediaCount      = Database::fetch("SELECT COUNT(*) c FROM media WHERE site_id = ?", [Database::siteId()])['c'];
$drafts          = Database::fetchAll("SELECT 'page' as type, title, id, updated_at FROM pages WHERE status='draft' AND site_id = ?
                                       UNION ALL
                                       SELECT 'post', title, id, updated_at FROM posts WHERE status='draft' AND site_id = ?
                                       ORDER BY updated_at DESC LIMIT 8",
                                      [Database::siteId(), Database::siteId()]);
$recentPosts     = Database::fetchAll("SELECT p.*, u.name as author_name FROM posts p
                                        JOIN users u ON u.id = p.author_id
                                        WHERE p.site_id = ?
                                        ORDER BY p.created_at DESC LIMIT 5", [Database::siteId()]);
$analytics       = Analytics::summary(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));

adminLayout('Dashboard', function() use ($pageCount, $postCount, $mediaCount, $drafts, $recentPosts, $analytics, $domainMismatch, $activeDomain, $storedDomain) {
?>

<?php if ($domainMismatch): ?>
<div class="alert alert-info" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
  <span>
    &#127760; <strong>Testing mode:</strong>
    You are on <strong><?= h($activeDomain) ?></strong>
    (the live domain is <strong><?= h($storedDomain) ?></strong>).
    When you are ready to go live, run the domain migration.
  </span>
  <a href="<?= siteUrl('admin/domain') ?>" class="btn btn-sm btn-primary">Go Live &rarr;</a>
</div>
<?php endif; ?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="num"><?= $pageCount ?></div>
    <div class="label">Published Pages</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= $postCount ?></div>
    <div class="label">Blog Posts</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= $mediaCount ?></div>
    <div class="label">Media Files</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($analytics['total']['views'] ?? 0) ?></div>
    <div class="label">Views (30 days)</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($analytics['total']['visitors'] ?? 0) ?></div>
    <div class="label">Visitors (30 days)</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px;">

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Site Traffic — Last 30 Days</h2>
      <a href="<?= siteUrl('admin/analytics') ?>" class="btn btn-sm btn-secondary">Full Report</a>
    </div>
    <div class="chart-container">
      <canvas id="trafficChart"></canvas>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const ctx = document.getElementById('trafficChart');
      if (!ctx) return;
      const data = <?= json_encode($analytics['byDay']) ?>;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.map(d => d.day),
          datasets: [{
            label: 'Views',
            data: data.map(d => d.views),
            borderColor: '#6b4226',
            backgroundColor: 'rgba(107,66,38,.1)',
            tension: .3,
            fill: true,
          },{
            label: 'Visitors',
            data: data.map(d => d.visitors),
            borderColor: '#c49a6c',
            tension: .3,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'top' } },
          scales: { y: { beginAtZero: true } }
        }
      });
    });
    </script>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">Quick Actions</h2>
    </div>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <a href="<?= siteUrl('admin/pages/new') ?>" class="btn btn-primary">+ New Page</a>
      <a href="<?= siteUrl('admin/posts/new') ?>" class="btn btn-primary">+ New Blog Post</a>
      <a href="<?= siteUrl('admin/media') ?>" class="btn btn-secondary">&#128247; Media Library</a>
    </div>

    <?php if ($drafts): ?>
    <div style="margin-top:20px;">
      <div class="card-title" style="margin-bottom:8px; font-size:13px; color:#767676; text-transform:uppercase; letter-spacing:.05em;">Drafts</div>
      <?php foreach ($drafts as $d): ?>
        <div style="font-size:13px; margin-bottom:6px; font-family:sans-serif;">
          <a href="<?= siteUrl('admin/' . $d['type'] . 's/' . $d['id'] . '/edit') ?>"
             style="color:var(--brown); text-decoration:none;">
            <?= h(truncate($d['title'], 40)) ?>
          </a>
          <span style="color:#aaa; font-size:11px;"><?= $d['type'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<div class="card" style="margin-top:20px;">
  <div class="card-header">
    <h2 class="card-title">Recent Blog Posts</h2>
    <a href="<?= siteUrl('admin/posts') ?>" class="btn btn-sm btn-secondary">View All</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Title</th><th>Author</th><th>Status</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentPosts as $p): ?>
        <tr>
          <td><?= h(truncate($p['title'], 50)) ?></td>
          <td><?= h($p['author_name']) ?></td>
          <td><span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
          <td style="font-size:12px; color:#999;"><?= formatDate($p['created_at'], 'M j, Y') ?></td>
          <td><a href="<?= siteUrl('admin/posts/' . $p['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php }); ?>
