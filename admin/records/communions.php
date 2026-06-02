<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

if (!Database::fetch("SHOW TABLES LIKE 'occi_communions'")) {
    adminLayout('First Communions', function() { ?>
      <div class="alert alert-error">NSR tables are missing. Please run pending migrations under
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>.</div>
    <?php });
    return;
}

$id     = (int)($_GET['id']     ?? 0);
$action = $_GET['action'] ?? ($id ? 'view' : 'list');
$parishes = Database::fetchAll("SELECT id, name, city, state FROM occi_parishes ORDER BY name ASC");

$defaultMinister = '';
$settingRow = Database::fetch("SELECT value FROM settings WHERE `key` = 'nsr_default_minister' LIMIT 1");
if ($settingRow) $defaultMinister = $settingRow['value'];

// ---------------------------------------------------------------
// Certificate
// ---------------------------------------------------------------
if ($action === 'certificate' && $id) {
    Auth::requirePermission('print_certificates');
    $r = Database::fetch("SELECT c.*, p.name AS parish_name, p.city AS parish_city, p.state AS parish_state
                          FROM occi_communions c LEFT JOIN occi_parishes p ON p.id = c.parish_id
                          WHERE c.id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Record not found.'); }

    $certHeader = '';
    $ch = Database::fetch("SELECT value FROM settings WHERE `key` = 'nsr_cert_header' LIMIT 1");
    if ($ch) $certHeader = $ch['value'];

    $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);
    $location = $r['parish_name'] ? h($r['parish_name']) . ', ' . h($r['parish_city']) . ', ' . h($r['parish_state']) : '&mdash;';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>First Communion Certificate &mdash; <?= h($fullName) ?></title>
<style>
  body { font-family: Georgia, serif; margin: 0; padding: 40px 60px; color: #1a1a1a; font-size: 14px; }
  .letterhead { text-align: center; border-bottom: 2px solid #5c3d1e; padding-bottom: 20px; margin-bottom: 30px; }
  .letterhead h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: 1px; text-transform: uppercase; }
  .letterhead h2 { font-size: 14px; font-weight: normal; margin: 0; color: #555; }
  .cert-title { text-align: center; font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 24px; }
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
  table.data th { text-align: left; color: #666; font-weight: normal; font-size: 12px; width: 200px; padding: 6px 10px 6px 0; vertical-align: top; }
  table.data td { padding: 6px 0; border-bottom: 1px solid #e8e0d4; font-size: 14px; }
  .section-head { font-size: 12px; font-weight: bold; text-transform: uppercase; color: #5c3d1e; letter-spacing: 1px; border-bottom: 1px solid #5c3d1e; margin: 24px 0 10px; padding-bottom: 4px; }
  .signatures { display: flex; gap: 60px; margin-top: 50px; }
  .sig-line { flex: 1; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; color: #555; }
  .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ccc; margin-top: 40px; padding-top: 12px; font-style: italic; }
  .print-btn { text-align: center; margin-bottom: 20px; }
  .print-btn button { padding: 8px 24px; font-size: 14px; cursor: pointer; }
  @media print { .print-btn { display: none; } body { padding: 20px 40px; } }
</style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">Print Certificate</button></div>
<div class="letterhead">
  <h1>Old Catholic Church International</h1>
  <h2><?= $certHeader ? h($certHeader) : 'Office of Canonical Records' ?></h2>
</div>
<div class="cert-title">Certificate of First Holy Communion</div>

<div class="section-head">Person</div>
<table class="data">
  <tr><th>Full Name</th><td><?= h($fullName) ?></td></tr>
</table>

<div class="section-head">Communion Details</div>
<table class="data">
  <tr><th>Date of Communion</th><td><?= date('F j, Y', strtotime($r['communion_date'])) ?></td></tr>
  <tr><th>Presider</th><td><?= h($r['presider']) ?></td></tr>
  <tr><th>Location</th><td><?= $location ?></td></tr>
</table>

<?php if ($r['baptism_date'] || $r['baptism_church']): ?>
<div class="section-head">Baptism Record</div>
<table class="data">
  <?php if ($r['baptism_date']): ?><tr><th>Baptism Date</th><td><?= date('F j, Y', strtotime($r['baptism_date'])) ?></td></tr><?php endif; ?>
  <?php if ($r['baptism_church']): ?><tr><th>Baptized At</th><td><?= h($r['baptism_church']) ?><?= $r['baptism_city'] ? ', ' . h($r['baptism_city']) . ', ' . h($r['baptism_state']) : '' ?></td></tr><?php endif; ?>
</table>
<?php endif; ?>

<?php if ($r['notations']): ?>
<div class="section-head">Notations</div>
<p style="margin:0; font-size:13px;"><?= nl2br(h($r['notations'])) ?></p>
<?php endif; ?>

<div class="signatures">
  <div class="sig-line">Presider</div>
  <div class="sig-line">Parish Registrar</div>
</div>
<div class="footer">
  Confidential Canonical Document &mdash; Church Use Only &mdash; Pax et Bonum<br>
  Old Catholic Church International &mdash; Issued <?= date('F j, Y') ?>
</div>
</body>
</html>
<?php
    exit;
}

// ---------------------------------------------------------------
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    if (($_POST['_action'] ?? '') === 'delete') {
        Auth::requirePermission('edit_records');
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::query("DELETE FROM occi_communions WHERE id = ?", [$delId]);
            flash('success', 'Record deleted.');
        }
        redirect(siteUrl('admin/records/communions'));
    }

    if (($_POST['_action'] ?? '') === 'save') {
        Auth::requirePermission('edit_records');
        $fields = [
            'communion_date'  => $_POST['communion_date']   ?? '',
            'first_name'      => trim($_POST['first_name']  ?? ''),
            'middle_name'     => trim($_POST['middle_name'] ?? ''),
            'last_name'       => trim($_POST['last_name']   ?? ''),
            'baptism_date'    => ($_POST['baptism_date']    ?? '') ?: null,
            'baptism_church'  => trim($_POST['baptism_church']  ?? ''),
            'baptism_city'    => trim($_POST['baptism_city']    ?? ''),
            'baptism_state'   => trim($_POST['baptism_state']   ?? ''),
            'presider'        => trim($_POST['presider']    ?? ''),
            'parish_id'       => ($_POST['parish_id']       ?? '') ?: null,
            'notations'       => trim($_POST['notations']   ?? ''),
        ];

        if (!$fields['first_name'] || !$fields['last_name'] || !$fields['communion_date'] || !$fields['presider']) {
            flash('error', 'First name, last name, communion date, and presider are required.');
            redirect(siteUrl('admin/records/communions' . ($id ? '/' . $id . '/edit' : '/new')));
        }

        if ($id) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Database::update('occi_communions', $fields, 'id = ?', [$id]);
            flash('success', 'Record updated.');
            redirect(siteUrl('admin/records/communions/' . $id));
        } else {
            $newId = Database::insert('occi_communions', $fields);
            flash('success', 'Record created.');
            redirect(siteUrl('admin/records/communions/' . $newId));
        }
    }
}

// ---------------------------------------------------------------
// View
// ---------------------------------------------------------------
if ($action === 'view' && $id) {
    $r = Database::fetch("SELECT c.*, p.name AS parish_name, p.city AS parish_city, p.state AS parish_state
                          FROM occi_communions c LEFT JOIN occi_parishes p ON p.id = c.parish_id
                          WHERE c.id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Record not found.'); }
    $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);

    adminLayout('Communion: ' . $fullName, function() use ($r, $id, $fullName) {
    ?>
    <div style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <a href="<?= siteUrl('admin/records/communions') ?>" class="btn btn-secondary btn-sm">&larr; All Communions</a>
      <?php if (Auth::hasPermission('print_certificates')): ?>
      <a href="<?= siteUrl('admin/records/communions/' . $id . '/certificate') ?>" target="_blank" class="btn btn-secondary btn-sm">&#128196; Print Certificate</a>
      <?php endif; ?>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <a href="<?= siteUrl('admin/records/communions/' . $id . '/edit') ?>" class="btn btn-primary btn-sm">Edit Record</a>
      <?php endif; ?>
    </div>
    <div class="card" style="max-width:820px;">
      <div class="card-header"><h2 class="card-title"><?= h($fullName) ?></h2></div>
      <table style="width:100%; font-size:14px; border-collapse:collapse;">
        <tr><td style="padding:7px 0; color:#888; width:200px;">Communion Date</td><td><?= h($r['communion_date']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Presider</td><td><?= h($r['presider']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Parish</td><td><?= $r['parish_name'] ? h($r['parish_name']) . ', ' . h($r['parish_city']) . ', ' . h($r['parish_state']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Baptism Date</td><td><?= $r['baptism_date'] ? h($r['baptism_date']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Baptized At</td><td><?= $r['baptism_church'] ? h($r['baptism_church']) . ($r['baptism_city'] ? ', ' . h($r['baptism_city']) . ', ' . h($r['baptism_state']) : '') : '&mdash;' ?></td></tr>
        <?php if ($r['notations']): ?>
        <tr><td style="padding:7px 0; color:#888; vertical-align:top;">Notations</td><td><?= nl2br(h($r['notations'])) ?></td></tr>
        <?php endif; ?>
      </table>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <div style="margin-top:20px; padding-top:16px; border-top:1px solid #eee;">
        <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this record?')">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Delete Record</button>
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
    $r = ['communion_date'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','baptism_date'=>'','baptism_church'=>'',
          'baptism_city'=>'','baptism_state'=>'','presider'=>$defaultMinister,'parish_id'=>'','notations'=>''];
    if ($action === 'edit' && $id) {
        $row = Database::fetch("SELECT * FROM occi_communions WHERE id = ?", [$id]);
        if (!$row) { http_response_code(404); die('Record not found.'); }
        $r = $row;
    }
    $pageTitle = $action === 'new' ? 'New First Communion Record' : 'Edit First Communion Record';
    adminLayout($pageTitle, function() use ($r, $id, $parishes) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/records/communions' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary btn-sm">&larr; Cancel</a>
    </div>
    <form method="post" action="<?= siteUrl('admin/records/communions' . ($id ? '/' . $id . '/edit' : '/new')) ?>" style="max-width:820px;">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="save">

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Person</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">First Name <span style="color:#dc3545;">*</span></label>
            <input type="text" name="first_name" value="<?= h($r['first_name']) ?>" required class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Middle Name</label>
            <input type="text" name="middle_name" value="<?= h($r['middle_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Last Name <span style="color:#dc3545;">*</span></label>
            <input type="text" name="last_name" value="<?= h($r['last_name']) ?>" required class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Communion Details</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Communion Date <span style="color:#dc3545;">*</span></label>
            <input type="date" name="communion_date" value="<?= h($r['communion_date']) ?>" required class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Presider <span style="color:#dc3545;">*</span></label>
            <input type="text" name="presider" value="<?= h($r['presider']) ?>" required class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Parish</label>
            <select name="parish_id" class="form-control">
              <option value="">-- Select Parish --</option>
              <?php foreach ($parishes as $p): ?>
              <option value="<?= $p['id'] ?>" <?= (string)($r['parish_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>, <?= h($p['city']) ?>, <?= h($p['state']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Baptism Information</h3></div>
        <div style="display:grid; grid-template-columns:1fr 2fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Baptism Date</label>
            <input type="date" name="baptism_date" value="<?= h($r['baptism_date'] ?? '') ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Baptism Church</label>
            <input type="text" name="baptism_church" value="<?= h($r['baptism_church']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">City</label>
            <input type="text" name="baptism_city" value="<?= h($r['baptism_city']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">State</label>
            <input type="text" name="baptism_state" value="<?= h($r['baptism_state']) ?>" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Notations</h3></div>
        <textarea name="notations" rows="4" class="form-control" style="width:100%; box-sizing:border-box;"><?= h($r['notations']) ?></textarea>
      </div>

      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary">Save Record</button>
        <a href="<?= siteUrl('admin/records/communions' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
    <?php
    });
    return;
}

// ---------------------------------------------------------------
// List
// ---------------------------------------------------------------
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['q'] ?? '');

if ($search) {
    $like  = '%' . $search . '%';
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_communions WHERE first_name LIKE ? OR last_name LIKE ?", [$like, $like])['n'];
    $rows  = Database::fetchAll("SELECT c.*, p.name AS parish_name FROM occi_communions c LEFT JOIN occi_parishes p ON p.id = c.parish_id WHERE c.first_name LIKE ? OR c.last_name LIKE ? ORDER BY c.communion_date DESC LIMIT ? OFFSET ?", [$like, $like, $perPage, $offset]);
} else {
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_communions")['n'];
    $rows  = Database::fetchAll("SELECT c.*, p.name AS parish_name FROM occi_communions c LEFT JOIN occi_parishes p ON p.id = c.parish_id ORDER BY c.communion_date DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
}

adminLayout('First Communions', function() use ($rows, $total, $page, $perPage, $search) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <form method="get" style="display:flex; gap:8px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by name..." class="form-control" style="width:240px;">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($search): ?><a href="<?= siteUrl('admin/records/communions') ?>" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
  </form>
  <?php if (Auth::hasPermission('edit_records')): ?>
  <a href="<?= siteUrl('admin/records/communions/new') ?>" class="btn btn-primary btn-sm">+ New Communion</a>
  <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">No first communion records found.</div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Communion Date</th><th>Presider</th><th>Parish</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td style="font-size:13px;"><?= h($r['communion_date']) ?></td>
          <td style="font-size:13px;"><?= h($r['presider']) ?></td>
          <td style="font-size:13px;"><?= $r['parish_name'] ? h($r['parish_name']) : '<span style="color:#aaa;">--</span>' ?></td>
          <td><a href="<?= siteUrl('admin/records/communions/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $page, $perPage, siteUrl('admin/records/communions?' . ($search ? 'q=' . urlencode($search) . '&' : ''))) ?>
<?php endif; ?>
<?php });
