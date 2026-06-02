<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

if (!Database::fetch("SHOW TABLES LIKE 'occi_parishes'")) {
    adminLayout('Parishes', function() { ?>
      <div class="alert alert-error">NSR tables are missing. Please run pending migrations under
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>.</div>
    <?php });
    return;
}

$id     = (int)($_GET['id']     ?? 0);
$action = $_GET['action'] ?? ($id ? 'view' : 'list');

// ---------------------------------------------------------------
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::requirePermission('edit_records');

    if (($_POST['_action'] ?? '') === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::query("DELETE FROM occi_parishes WHERE id = ?", [$delId]);
            flash('success', 'Parish deleted.');
        }
        redirect(siteUrl('admin/records/parishes'));
    }

    if (($_POST['_action'] ?? '') === 'save') {
        $fields = [
            'name'             => trim($_POST['name']             ?? ''),
            'city'             => trim($_POST['city']             ?? ''),
            'state'            => trim($_POST['state']            ?? ''),
            'cert_template_url'=> trim($_POST['cert_template_url']?? ''),
        ];

        if (!$fields['name'] || !$fields['city'] || !$fields['state']) {
            flash('error', 'Parish name, city, and state are required.');
            redirect(siteUrl('admin/records/parishes' . ($id ? '/' . $id . '/edit' : '/new')));
        }

        if ($id) {
            Database::update('occi_parishes', $fields, 'id = ?', [$id]);
            flash('success', 'Parish updated.');
            redirect(siteUrl('admin/records/parishes/' . $id));
        } else {
            $newId = Database::insert('occi_parishes', $fields);
            flash('success', 'Parish created.');
            redirect(siteUrl('admin/records/parishes/' . $newId));
        }
    }
}

// ---------------------------------------------------------------
// View
// ---------------------------------------------------------------
if ($action === 'view' && $id) {
    $r = Database::fetch("SELECT * FROM occi_parishes WHERE id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Parish not found.'); }

    adminLayout('Parish: ' . $r['name'], function() use ($r, $id) {
    ?>
    <div style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <a href="<?= siteUrl('admin/records/parishes') ?>" class="btn btn-secondary btn-sm">&larr; All Parishes</a>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <a href="<?= siteUrl('admin/records/parishes/' . $id . '/edit') ?>" class="btn btn-primary btn-sm">Edit Parish</a>
      <?php endif; ?>
    </div>
    <div class="card" style="max-width:600px;">
      <div class="card-header"><h2 class="card-title"><?= h($r['name']) ?></h2></div>
      <table style="width:100%; font-size:14px; border-collapse:collapse;">
        <tr><td style="padding:7px 0; color:#888; width:180px;">City</td><td><?= h($r['city']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">State</td><td><?= h($r['state']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Cert. Template URL</td><td><?= $r['cert_template_url'] ? '<a href="' . h($r['cert_template_url']) . '" target="_blank">' . h($r['cert_template_url']) . '</a>' : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Added</td><td style="font-size:12px;"><?= h($r['created_at']) ?></td></tr>
      </table>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <div style="margin-top:20px; padding-top:16px; border-top:1px solid #eee;">
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this parish? Records that reference it will lose the parish link.')">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Delete Parish</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php
    });
    return;
}

// ---------------------------------------------------------------
// New / Edit form
// ---------------------------------------------------------------
if (in_array($action, ['new', 'edit'])) {
    Auth::requirePermission('edit_records');
    $r = ['name'=>'','city'=>'','state'=>'','cert_template_url'=>''];
    if ($action === 'edit' && $id) {
        $row = Database::fetch("SELECT * FROM occi_parishes WHERE id = ?", [$id]);
        if (!$row) { http_response_code(404); die('Parish not found.'); }
        $r = $row;
    }
    $pageTitle = $action === 'new' ? 'New Parish' : 'Edit Parish';
    adminLayout($pageTitle, function() use ($r, $id) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/records/parishes' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary btn-sm">&larr; Cancel</a>
    </div>
    <form method="post" action="<?= siteUrl('admin/records/parishes' . ($id ? '/' . $id . '/edit' : '/new')) ?>" style="max-width:600px;">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="save">

      <div class="card" style="margin-bottom:16px;">
        <div style="display:grid; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Parish Name <span style="color:#dc3545;">*</span></label>
            <input type="text" name="name" value="<?= h($r['name']) ?>" required class="form-control">
          </div>
          <div style="display:grid; grid-template-columns:2fr 1fr; gap:14px;">
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">City <span style="color:#dc3545;">*</span></label>
              <input type="text" name="city" value="<?= h($r['city']) ?>" required class="form-control">
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">State <span style="color:#dc3545;">*</span></label>
              <input type="text" name="state" value="<?= h($r['state']) ?>" required class="form-control">
            </div>
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Certificate Template URL</label>
            <input type="url" name="cert_template_url" value="<?= h($r['cert_template_url']) ?>" class="form-control" placeholder="https://...">
            <p style="font-size:12px; color:#888; margin:4px 0 0;">Optional: link to a parish-specific certificate template.</p>
          </div>
        </div>
      </div>

      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary">Save Parish</button>
        <a href="<?= siteUrl('admin/records/parishes' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
    <?php
    });
    return;
}

// ---------------------------------------------------------------
// List
// ---------------------------------------------------------------
$rows  = Database::fetchAll("SELECT p.*, COUNT(b.id) AS baptism_count FROM occi_parishes p
                              LEFT JOIN occi_baptisms b ON b.parish_id = p.id
                              GROUP BY p.id ORDER BY p.name ASC");
$total = count($rows);

adminLayout('Parishes', function() use ($rows, $total) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <span style="font-size:13px; color:#666;"><?= $total ?> parish<?= $total !== 1 ? 'es' : '' ?> registered</span>
  <?php if (Auth::hasPermission('edit_records')): ?>
  <a href="<?= siteUrl('admin/records/parishes/new') ?>" class="btn btn-primary btn-sm">+ New Parish</a>
  <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">
    No parishes have been added yet.<br><br>
    <a href="<?= siteUrl('admin/records/parishes/new') ?>" class="btn btn-primary">Add Your First Parish</a>
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead><tr><th>Name</th><th>City</th><th>State</th><th>Baptisms</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td style="font-size:13px;"><?= h($r['city']) ?></td>
          <td style="font-size:13px;"><?= h($r['state']) ?></td>
          <td style="font-size:13px; text-align:center;"><?= (int)$r['baptism_count'] ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= siteUrl('admin/records/parishes/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a>
            <?php if (Auth::hasPermission('edit_records')): ?>
            <a href="<?= siteUrl('admin/records/parishes/' . $r['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php });
