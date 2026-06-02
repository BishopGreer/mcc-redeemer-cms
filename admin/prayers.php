<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

// Guard: table may not exist or may be missing site_id (pre-network schema)
$prayerTableOk = Database::fetch("SHOW TABLES LIKE 'prayer_requests'")
              && Database::fetch("SHOW COLUMNS FROM `prayer_requests` LIKE 'site_id'");
if (!$prayerTableOk) {
    adminLayout('Prayer Requests', function() { ?>
      <div class="alert alert-error">
        The <strong>prayer_requests</strong> table is missing or needs to be updated.
        Please go to <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
        and run pending migrations, then return here.
      </div>
    <?php });
    return;
}

$id = (int)($_GET['id'] ?? 0);

// ---- Detail view ----
if ($id) {
    $req = Database::fetch("SELECT * FROM prayer_requests WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
    if (!$req) { http_response_code(404); die('Not found.'); }
    if (!$req['is_read']) {
        Database::update('prayer_requests', ['is_read' => 1], 'id = ? AND site_id = ?', [$id, Database::siteId()]);
    }

    adminLayout('Prayer Request', function() use ($req) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/prayers') ?>" class="btn btn-secondary btn-sm">&larr; All Requests</a>
    </div>

    <div class="card" style="max-width:760px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">
          <?= $req['is_anonymous'] ? '<em style="color:#aaa;">Anonymous</em>' : h($req['name']) ?>
        </h2>
        <span style="font-family:sans-serif; font-size:12px; color:var(--slate-lt);">
          <?= date('F j, Y \a\t g:i a', strtotime($req['created_at'])) ?>
        </span>
      </div>

      <table style="width:100%; font-size:14px; border-collapse:collapse; margin-bottom:20px;">
        <?php if (!$req['is_anonymous']): ?>
        <tr>
          <td style="padding:7px 0; color:#888; width:140px;">Name</td>
          <td><?= h($req['name']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($req['email']): ?>
        <tr>
          <td style="padding:7px 0; color:#888;">Email</td>
          <td><a href="mailto:<?= h($req['email']) ?>"><?= h($req['email']) ?></a></td>
        </tr>
        <?php endif; ?>
        <?php if ($req['phone']): ?>
        <tr>
          <td style="padding:7px 0; color:#888;">Phone</td>
          <td><?= h($req['phone']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($req['intention_type']): ?>
        <tr>
          <td style="padding:7px 0; color:#888;">Type</td>
          <td><?= h($req['intention_type']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td style="padding:7px 0; color:#888;">Anonymous</td>
          <td><?= $req['is_anonymous'] ? 'Yes' : 'No' ?></td>
        </tr>
      </table>

      <div style="background:#f9f5f0; border-radius:6px; padding:20px; font-size:15px; line-height:1.75; white-space:pre-wrap; font-style:italic; color:#4a4a4a;"><?= h($req['intention']) ?></div>

      <div style="margin-top:20px; display:flex; gap:10px;">
        <?php if ($req['email'] && !$req['is_anonymous']): ?>
          <a href="mailto:<?= h($req['email']) ?>?subject=Your Prayer Request" class="btn btn-primary">Reply via Email</a>
        <?php endif; ?>
        <a href="<?= siteUrl('admin/prayers') ?>" class="btn btn-secondary">Back to List</a>
      </div>
    </div>
    <?php
    });
    return;
}

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    $delId = (int)($_POST['id'] ?? 0);
    if ($delId) {
        Database::query("DELETE FROM prayer_requests WHERE id = ? AND site_id = ?", [$delId, Database::siteId()]);
        flash('success', 'Prayer request deleted.');
    }
    redirect(siteUrl('admin/prayers'));
}

// ---- List ----
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;
$filter  = $_GET['filter'] ?? 'all';

$where       = $filter === 'unread' ? 'WHERE is_read = 0 AND site_id = ?' : 'WHERE site_id = ?';
$total       = Database::fetch("SELECT COUNT(*) as n FROM prayer_requests $where", [Database::siteId()])['n'];
$rows        = Database::fetchAll(
    "SELECT * FROM prayer_requests $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [Database::siteId(), $perPage, $offset]
);
$unreadCount = Database::fetch("SELECT COUNT(*) as n FROM prayer_requests WHERE is_read = 0 AND site_id = ?", [Database::siteId()])['n'];

adminLayout('Prayer Requests', function() use ($rows, $total, $page, $perPage, $filter, $unreadCount) {
?>

<div style="display:flex; gap:8px; margin-bottom:16px;">
  <a href="<?= siteUrl('admin/prayers') ?>"
     class="btn btn-sm <?= $filter !== 'unread' ? 'btn-primary' : 'btn-secondary' ?>">
    All (<?= $total ?>)
  </a>
  <a href="<?= siteUrl('admin/prayers?filter=unread') ?>"
     class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-secondary' ?>">
    Unread (<?= $unreadCount ?>)
  </a>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">No prayer requests found.</div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:28px;"></th>
          <th>Name</th>
          <th>Type</th>
          <th>Intention (preview)</th>
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
            <td><?= $r['is_anonymous'] ? '<em style="color:#aaa;">Anonymous</em>' : h($r['name']) ?></td>
            <td style="font-size:13px; color:#666;"><?= h($r['intention_type'] ?: '—') ?></td>
            <td style="font-size:13px; color:#666; max-width:320px;">
              <?= h(mb_strimwidth($r['intention'], 0, 100, '…')) ?>
            </td>
            <td style="font-size:12px; color:#999; white-space:nowrap;">
              <?= date('M j, Y g:i a', strtotime($r['created_at'])) ?>
            </td>
            <td style="white-space:nowrap;">
              <a href="<?= siteUrl('admin/prayers/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this prayer request?')">
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
  <?= pagination($total, $page, $perPage, siteUrl('admin/prayers' . ($filter === 'unread' ? '?filter=unread&' : '?'))) ?>
<?php endif; ?>

<?php });
