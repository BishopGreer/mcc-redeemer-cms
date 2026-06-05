<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once BASE_PATH . '/core/Events.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_events');

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        Database::query("DELETE FROM events WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
        PageCache::clearAll();
        flash('success', 'Event deleted.');
    }
    redirect(siteUrl('admin/events'));
}

$filter = $_GET['status'] ?? 'all';
$where  = $filter === 'published' ? "AND status = 'published'"
        : ($filter === 'draft'    ? "AND status = 'draft'" : '');

$events = Database::fetchAll(
    "SELECT * FROM events WHERE site_id = ? {$where} ORDER BY start_dt ASC",
    [Database::siteId()]
);

adminLayout('Events', function() use ($events, $filter) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <div>
    <h1 class="page-title" style="margin:0;">Events</h1>
    <p style="margin:4px 0 0; color:#6b7280;">Manage church events and recurring schedules.</p>
  </div>
  <a href="<?= siteUrl('admin/events/new') ?>" class="btn btn-primary">+ Add Event</a>
</div>

<!-- Status filter tabs -->
<div style="display:flex; gap:8px; margin-bottom:20px; border-bottom:2px solid #e5e7eb; padding-bottom:0;">
  <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $k => $label): ?>
  <a href="<?= siteUrl('admin/events?status=' . $k) ?>"
     style="padding:6px 14px; font-size:0.875rem; font-weight:600; text-decoration:none; border-bottom:2px solid <?= $filter === $k ? '#6B3FA0' : 'transparent' ?>; margin-bottom:-2px; color:<?= $filter === $k ? '#6B3FA0' : '#6b7280' ?>;">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (empty($events)): ?>
<div class="card" style="text-align:center; padding:48px 24px;">
  <p style="color:#6b7280; font-size:1.1rem;">No events yet.</p>
  <a href="<?= siteUrl('admin/events/new') ?>" class="btn btn-primary" style="margin-top:16px;">Add Your First Event</a>
</div>
<?php else: ?>
<div class="card" style="padding:0; overflow:hidden;">
  <table class="data-table" style="margin:0;">
    <thead>
      <tr>
        <th>Title</th>
        <th>Date / Time</th>
        <th>Recurrence</th>
        <th>Status</th>
        <th style="width:120px;"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $e): ?>
    <tr>
      <td>
        <strong><?= h($e['title']) ?></strong>
        <?php if ($e['location']): ?>
        <div style="font-size:0.8rem; color:#9ca3af; margin-top:2px;">&#128205; <?= h($e['location']) ?></div>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap; font-size:0.875rem;">
        <?php
        $s = new DateTimeImmutable($e['start_dt']);
        echo $s->format('M j, Y');
        if (!$e['all_day']) echo '<br><span style="color:#6b7280;">' . $s->format('g:i a') . '</span>';
        else echo '<br><span style="color:#6b7280;">All day</span>';
        ?>
      </td>
      <td style="font-size:0.85rem;">
        <?php $rl = Events::recurrenceLabel($e); ?>
        <?php if ($rl): ?>
        <span style="background:#ede9fe; color:#6B3FA0; padding:2px 8px; border-radius:10px; font-size:0.78rem; font-weight:600;"><?= h($rl) ?></span>
        <?php else: ?>
        <span style="color:#9ca3af;">One-time</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="badge badge-<?= $e['status'] === 'published' ? 'success' : 'secondary' ?>">
          <?= ucfirst($e['status']) ?>
        </span>
      </td>
      <td style="white-space:nowrap;">
        <a href="<?= siteUrl('admin/events/' . $e['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('Delete &quot;<?= h(addslashes($e['title'])) ?>&quot;? This cannot be undone.')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $e['id'] ?>">
          <button class="btn btn-sm btn-danger">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
});
