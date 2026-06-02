<?php
// Network Admin — Dashboard
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireSuperAdmin();

$sites     = Database::fetchAll("SELECT * FROM network_sites ORDER BY id ASC");
$siteCount = count($sites);

// Per-site quick stats
$stats = [];
foreach ($sites as $site) {
    $sid = $site['id'];
    $stats[$sid] = [
        'pages'       => Database::fetch("SELECT COUNT(*) c FROM pages WHERE site_id = ?", [$sid])['c'] ?? 0,
        'posts'       => Database::fetch("SELECT COUNT(*) c FROM posts WHERE site_id = ?", [$sid])['c'] ?? 0,
    ];
}

adminLayout('Network Dashboard', function() use ($sites, $siteCount, $stats) {
?>
<div class="stats-grid">
  <div class="stat-card">
    <div class="num"><?= $siteCount ?></div>
    <div class="label">Total Sites</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= array_sum(array_column($stats, 'posts')) ?></div>
    <div class="label">Blog Posts (all sites)</div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h2 class="card-title">Sites in This Network</h2>
    <a href="<?= networkUrl('admin/network/sites') ?>" class="btn btn-sm btn-primary">Manage Sites</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Site Name</th>
          <th>Subdomain</th>
          <th>Status</th>
          <th>Pages</th>
          <th>Posts</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sites as $site): ?>
        <tr>
          <td><?= $site['id'] ?></td>
          <td><strong><?= h($site['name']) ?></strong></td>
          <td>
            <?php if ($site['subdomain'] === ''): ?>
              <em style="color:#999;">main domain</em>
            <?php else: ?>
              <code><?= h($site['subdomain']) ?>.<?= h(NETWORK_BASE_DOMAIN) ?></code>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= $site['status'] === 'active' ? 'published' : 'draft' ?>">
              <?= $site['status'] ?>
            </span>
          </td>
          <td><?= $stats[$site['id']]['pages'] ?></td>
          <td><?= $stats[$site['id']]['posts'] ?></td>
          <td>
            <a href="<?= networkUrl('admin/network/sites?edit=' . $site['id']) ?>"
               class="btn btn-sm btn-secondary">Edit</a>
            <a href="<?= subsiteUrl($site['subdomain'], 'admin/') ?>"
               class="btn btn-sm btn-secondary" target="_blank">Site Admin &#8599;</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }); ?>
