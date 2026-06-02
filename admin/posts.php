<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    Database::delete('posts', 'id = ? AND site_id = ?', [(int)$_POST['id'], Database::siteId()]);
    flash('success', 'Post deleted.');
    redirect(siteUrl('admin/posts'));
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';

$where  = "WHERE p.site_id = ?";
$params = [Database::siteId()];
if ($search) { $where .= " AND p.title LIKE ?"; $params[] = "%$search%"; }
if ($status) { $where .= " AND p.status = ?"; $params[] = $status; }

$total = Database::fetch("SELECT COUNT(*) c FROM posts p $where", $params)['c'];
$posts = Database::fetchAll(
    "SELECT p.*, u.name as author_name, c.name as cat_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     LEFT JOIN categories c ON c.id = p.category_id
     $where ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);

adminLayout('Blog Posts', function() use ($posts, $total, $page, $perPage, $search, $status) {
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <form method="get" style="display:flex; gap:8px; flex-wrap:wrap;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search posts…" class="form-control" style="width:200px;">
    <select name="status" class="form-control" style="width:130px;">
      <option value="">All Statuses</option>
      <option value="published" <?= $status==='published'?'selected':'' ?>>Published</option>
      <option value="draft"     <?= $status==='draft'    ?'selected':'' ?>>Draft</option>
    </select>
    <button class="btn btn-secondary">Filter</button>
    <?php if ($search || $status): ?><a href="<?= siteUrl('admin/posts') ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
  </form>
  <a href="<?= siteUrl('admin/posts/new') ?>" class="btn btn-primary">+ New Post</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Title</th><th>Category</th><th>Author</th><th>Status</th><th>Date</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($posts)): ?>
          <tr><td colspan="6" style="text-align:center; color:#aaa; padding:24px;">No posts found.</td></tr>
        <?php endif; ?>
        <?php foreach ($posts as $p): ?>
        <tr>
          <td>
            <a href="<?= siteUrl('admin/posts/' . $p['id'] . '/edit') ?>"
               style="color:var(--brown); text-decoration:none; font-weight:500;"><?= h($p['title']) ?></a>
          </td>
          <td><?= h($p['cat_name'] ?? '—') ?></td>
          <td><?= h($p['author_name']) ?></td>
          <td>
            <?php
            $isScheduled = $p['status'] === 'published'
                        && $p['published_at']
                        && strtotime($p['published_at']) > time();
            if ($isScheduled):
            ?>
              <span class="badge" style="background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe;">scheduled</span>
            <?php else: ?>
              <span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px; color:#999;">
            <?php if ($isScheduled): ?>
              <span style="color:#2563eb;">&#128337; <?= formatDate($p['published_at'], 'M j, Y g:i A') ?></span>
            <?php else: ?>
              <?= formatDate($p['published_at'] ?: $p['created_at'], 'M j, Y') ?>
            <?php endif; ?>
          </td>
          <td>
            <div class="actions">
              <a href="<?= siteUrl('admin/posts/' . $p['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
              <?php if ($p['status'] === 'published' && !$isScheduled): ?>
                <a href="<?= siteUrl('blog/' . $p['slug']) ?>" target="_blank" class="btn btn-sm btn-secondary">View</a>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this post?');" style="display:inline;">
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
  <?= pagination($total, $page, $perPage, siteUrl('admin/posts') . '?' . http_build_query(['q'=>$search,'status'=>$status])) ?>
</div>

<?php }); ?>
