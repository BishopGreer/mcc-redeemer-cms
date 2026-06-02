<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

// Guard: table may not exist or may be missing site_id (pre-network schema)
$contactTableOk = Database::fetch("SHOW TABLES LIKE 'contact_submissions'")
               && Database::fetch("SHOW COLUMNS FROM `contact_submissions` LIKE 'site_id'");
if (!$contactTableOk) {
    adminLayout('Contact Messages', function() { ?>
      <div class="alert alert-error">
        The <strong>contact_submissions</strong> table is missing or needs to be updated.
        Please go to <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
        and run pending migrations, then return here.
      </div>
    <?php });
    return;
}

$id = (int)($_GET['id'] ?? 0);

// Mark as read and show detail
if ($id) {
    $submission = Database::fetch("SELECT * FROM contact_submissions WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
    if (!$submission) { http_response_code(404); die('Not found.'); }
    if (!$submission['is_read']) {
        Database::update('contact_submissions', ['is_read' => 1], 'id = ? AND site_id = ?', [$id, Database::siteId()]);
    }

    adminLayout('Contact: ' . $submission['name'], function() use ($submission) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/contacts') ?>" class="btn btn-secondary btn-sm">&larr; All Messages</a>
    </div>

    <div class="card" style="max-width:760px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title"><?= h($submission['name']) ?></h2>
        <span style="font-family:sans-serif; font-size:12px; color:var(--slate-lt);">
          <?= date('F j, Y \a\t g:i a', strtotime($submission['created_at'])) ?>
        </span>
      </div>

      <table style="width:100%; font-size:14px; border-collapse:collapse; margin-bottom:20px;">
        <tr>
          <td style="padding:8px 0; color:#888; width:130px;">From</td>
          <td><?= h($submission['name']) ?></td>
        </tr>
        <tr>
          <td style="padding:8px 0; color:#888;">Email</td>
          <td><a href="mailto:<?= h($submission['email']) ?>"><?= h($submission['email']) ?></a></td>
        </tr>
        <?php if ($submission['phone']): ?>
        <tr>
          <td style="padding:8px 0; color:#888;">Phone</td>
          <td><a href="tel:<?= h($submission['phone']) ?>"><?= h($submission['phone']) ?></a></td>
        </tr>
        <?php endif; ?>
        <?php if ($submission['subject']): ?>
        <tr>
          <td style="padding:8px 0; color:#888;">Subject</td>
          <td><?= h($submission['subject']) ?></td>
        </tr>
        <?php endif; ?>
      </table>

      <div style="background:#f9f5f0; border-radius:6px; padding:20px; font-size:15px; line-height:1.75; white-space:pre-wrap;"><?= h($submission['message']) ?></div>

      <div style="margin-top:20px; display:flex; gap:10px;">
        <a href="mailto:<?= h($submission['email']) ?>?subject=Re: <?= rawurlencode($submission['subject'] ?: 'Your Message') ?>"
           class="btn btn-primary">Reply via Email</a>
        <a href="<?= siteUrl('admin/contacts') ?>" class="btn btn-secondary">Back to List</a>
      </div>
    </div>
    <?php
    });
    return;
}

// Delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    $delId = (int)($_POST['id'] ?? 0);
    if ($delId) {
        Database::query("DELETE FROM contact_submissions WHERE id = ? AND site_id = ?", [$delId, Database::siteId()]);
        flash('success', 'Message deleted.');
    }
    redirect(siteUrl('admin/contacts'));
}

// List
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;
$filter  = $_GET['filter'] ?? 'all';

$where  = $filter === 'unread' ? 'WHERE is_read = 0 AND site_id = ?' : 'WHERE site_id = ?';
$total  = Database::fetch("SELECT COUNT(*) as n FROM contact_submissions $where", [Database::siteId()])['n'];
$rows   = Database::fetchAll(
    "SELECT * FROM contact_submissions $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [Database::siteId(), $perPage, $offset]
);
$unreadCount = Database::fetch("SELECT COUNT(*) as n FROM contact_submissions WHERE is_read = 0 AND site_id = ?", [Database::siteId()])['n'];

adminLayout('Contact Messages', function() use ($rows, $total, $page, $perPage, $filter, $unreadCount) {
?>

<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <div style="display:flex; gap:8px;">
    <a href="<?= siteUrl('admin/contacts') ?>"
       class="btn btn-sm <?= $filter !== 'unread' ? 'btn-primary' : 'btn-secondary' ?>">
      All (<?= $total ?>)
    </a>
    <a href="<?= siteUrl('admin/contacts?filter=unread') ?>"
       class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-secondary' ?>">
      Unread (<?= $unreadCount ?>)
    </a>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">
    No messages found.
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:28px;"></th>
          <th>Name</th>
          <th>Email</th>
          <th>Subject</th>
          <th>Received</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="<?= !$r['is_read'] ? 'font-weight:600; background:#fffbf5;' : '' ?>">
            <td style="text-align:center;">
              <?= !$r['is_read'] ? '<span style="color:#e65100; font-size:10px;">&#9679;</span>' : '' ?>
            </td>
            <td><?= h($r['name']) ?></td>
            <td style="font-size:13px;"><?= h($r['email']) ?></td>
            <td style="font-size:13px; color:#666;"><?= h($r['subject'] ?: '—') ?></td>
            <td style="font-size:12px; color:#999; white-space:nowrap;">
              <?= date('M j, Y g:i a', strtotime($r['created_at'])) ?>
            </td>
            <td style="white-space:nowrap;">
              <a href="<?= siteUrl('admin/contacts/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this message?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= pagination($total, $page, $perPage, siteUrl('admin/contacts' . ($filter === 'unread' ? '?filter=unread&' : '?'))) ?>
<?php endif; ?>

<?php });
