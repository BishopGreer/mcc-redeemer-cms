<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requireRole('editor');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        Database::query("DELETE FROM board_members WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
        PageCache::clearAll();
        flash('success', 'Board member removed.');
    }
    redirect(siteUrl('admin/board'));
}

// Handle reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    Auth::verifyCsrf();
    $order = $_POST['order'] ?? [];
    foreach ($order as $pos => $memberId) {
        Database::query(
            "UPDATE board_members SET display_order = ? WHERE id = ? AND site_id = ?",
            [(int)$pos, (int)$memberId, Database::siteId()]
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

$members = Database::fetchAll(
    "SELECT b.*, m.path as photo_path, m.alt_text as photo_alt
     FROM board_members b
     LEFT JOIN media m ON m.id = b.photo_id
     WHERE b.site_id = ?
     ORDER BY b.display_order ASC, b.name ASC",
    [Database::siteId()]
);

adminLayout('Board of Directors', function() use ($members) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <div>
    <h1 class="page-title" style="margin:0;">Board of Directors</h1>
    <p style="margin:4px 0 0; color:#6b7280;">Manage leadership profiles displayed on the website.</p>
  </div>
  <a href="<?= siteUrl('admin/board/new') ?>" class="btn btn-primary">+ Add Member</a>
</div>

<?php if (empty($members)): ?>
<div class="card" style="text-align:center; padding:48px 24px;">
  <p style="color:#6b7280; font-size:1.1rem;">No board members yet.</p>
  <a href="<?= siteUrl('admin/board/new') ?>" class="btn btn-primary" style="margin-top:16px;">Add Your First Member</a>
</div>
<?php else: ?>

<div class="card" style="padding:0; overflow:hidden;">
  <p style="padding:16px 20px; margin:0; color:#6b7280; font-size:0.875rem; border-bottom:1px solid #e5e7eb;">
    Drag rows to reorder how members appear on the website.
  </p>
  <table class="data-table" style="margin:0;">
    <thead>
      <tr>
        <th style="width:32px;"></th>
        <th>Name</th>
        <th>Title</th>
        <th>Status</th>
        <th style="width:120px;">Actions</th>
      </tr>
    </thead>
    <tbody id="board-list">
      <?php foreach ($members as $m): ?>
      <tr data-id="<?= $m['id'] ?>" style="cursor:grab;">
        <td style="color:#9ca3af; font-size:1.2rem; text-align:center;">&#8597;</td>
        <td>
          <?php if ($m['photo_path']): ?>
          <img src="<?= siteUrl($m['photo_path']) ?>" alt="<?= h($m['photo_alt'] ?: $m['name']) ?>"
               style="width:36px; height:36px; border-radius:50%; object-fit:cover; vertical-align:middle; margin-right:8px;">
          <?php endif; ?>
          <strong><?= h($m['name']) ?></strong>
        </td>
        <td><?= h($m['title'] ?? '') ?></td>
        <td>
          <span class="badge badge-<?= $m['is_active'] ? 'success' : 'secondary' ?>">
            <?= $m['is_active'] ? 'Active' : 'Hidden' ?>
          </span>
        </td>
        <td>
          <a href="<?= siteUrl('admin/board/' . $m['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Remove <?= h(addslashes($m['name'])) ?>?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn btn-sm btn-danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
// Simple drag-to-reorder
(function() {
  var list = document.getElementById('board-list');
  var dragging = null;
  list.querySelectorAll('tr').forEach(function(row) {
    row.draggable = true;
    row.addEventListener('dragstart', function() { dragging = this; this.style.opacity = '0.4'; });
    row.addEventListener('dragend',   function() { this.style.opacity = ''; save(); });
    row.addEventListener('dragover',  function(e) {
      e.preventDefault();
      var after = getAfter(list, e.clientY);
      if (after == null) list.appendChild(dragging);
      else list.insertBefore(dragging, after);
    });
  });
  function getAfter(container, y) {
    var rows = [...container.querySelectorAll('tr:not([style*="opacity: 0.4"])')];
    return rows.reduce(function(closest, child) {
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) return { offset: offset, element: child };
      return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }
  function save() {
    var ids = [...list.querySelectorAll('tr')].map(function(r, i) { return r.dataset.id; });
    var form = new FormData();
    form.append('action', 'reorder');
    form.append('_csrf', '<?= Auth::csrf() ?>');
    ids.forEach(function(id, i) { form.append('order[' + i + ']', id); });
    fetch('<?= siteUrl('admin/board') ?>', { method: 'POST', body: form });
  }
})();
</script>
<?php endif; ?>
<?php
});
