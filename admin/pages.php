<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    Auth::requireRole('editor');
    $id = (int)($_POST['id'] ?? 0);
    Database::delete('pages', 'id = ? AND site_id = ?', [$id, Database::siteId()]);
    flash('success', 'Page deleted.');
    redirect(siteUrl('admin/pages'));
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['q'] ?? '');

$where  = $search ? "WHERE p.site_id = ? AND p.title LIKE ?" : "WHERE p.site_id = ?";
$params = $search ? [Database::siteId(), "%$search%"] : [Database::siteId()];

$total  = Database::fetch("SELECT COUNT(*) c FROM pages p $where", $params)['c'];
$pages  = Database::fetchAll(
    "SELECT p.*, u.name as author_name FROM pages p
     LEFT JOIN users u ON u.id = p.author_id
     $where
     ORDER BY p.menu_order ASC, p.updated_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

adminLayout('Pages', function() use ($pages, $total, $page, $perPage, $search) {
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
  <form method="get" style="display:flex; gap:8px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search pages…"
           class="form-control" style="width:220px;">
    <button class="btn btn-secondary">Search</button>
    <?php if ($search): ?><a href="<?= siteUrl('admin/pages') ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
  </form>
  <a href="<?= siteUrl('admin/pages/new') ?>" class="btn btn-primary">+ New Page</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Slug</th>
          <th>Status</th>
          <th>In Nav</th>
          <th>Order</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pages)): ?>
          <tr><td colspan="7" style="text-align:center; color:#aaa; padding:24px;">No pages found.</td></tr>
        <?php endif; ?>
        <?php foreach ($pages as $p): ?>
        <tr>
          <td>
            <a href="<?= siteUrl('admin/pages/' . $p['id'] . '/edit') ?>"
               style="color:var(--brown); text-decoration:none; font-weight:500;">
              <?= h($p['title']) ?>
            </a>
          </td>
          <td>
            <?php if (($p['page_type'] ?? 'page') === 'link'): ?>
              <span style="font-size:11px; background:#e8f0fe; color:#1a56c4; border-radius:3px; padding:1px 6px; font-weight:600;">LINK</span>
              <code style="font-size:12px; color:#888;"><?= h($p['link_url'] ?? '') ?></code>
            <?php else: ?>
              <code style="font-size:12px;">/<?= h($p['slug']) ?></code>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
          <td><?= $p['show_in_nav'] ? '&#10003;' : '' ?></td>
          <td><?= $p['menu_order'] ?></td>
          <td style="font-size:12px; color:#999;"><?= formatDate($p['updated_at'], 'M j, Y') ?></td>
          <td>
            <div class="actions">
              <a href="<?= siteUrl('admin/pages/' . $p['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
              <?php if (($p['page_type'] ?? 'page') === 'link' && !empty($p['link_url'])): ?>
                <a href="<?= h($p['link_url']) ?>" target="_blank" class="btn btn-sm btn-secondary">Visit</a>
              <?php else: ?>
                <a href="<?= siteUrl($p['slug']) ?>" target="_blank" class="btn btn-sm btn-secondary">View</a>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this page?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $page, $perPage, siteUrl('admin/pages') . ($search ? '?q=' . urlencode($search) : '')) ?>
</div>

<?php }); ?>
