<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

// Check table exists
if (!Database::fetch("SHOW TABLES LIKE 'occi_baptisms'")) {
    adminLayout('Baptisms', function() { ?>
      <div class="alert alert-error">
        NSR tables are missing. Please run pending migrations under
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>.
      </div>
    <?php });
    return;
}

$id     = (int)($_GET['id']     ?? 0);
$action = $_GET['action'] ?? ($id ? 'view' : 'list');

$parishes = Database::fetchAll("SELECT id, name, city, state FROM occi_parishes ORDER BY name ASC");

// ---- NSR default minister setting ----
$defaultMinister = '';
$settingRow = Database::fetch("SELECT value FROM settings WHERE `key` = 'nsr_default_minister' LIMIT 1");
if ($settingRow) $defaultMinister = $settingRow['value'];

// ---------------------------------------------------------------
// Certificate (standalone HTML, no adminLayout)
// ---------------------------------------------------------------
if ($action === 'certificate' && $id) {
    Auth::requirePermission('print_certificates');
    $r = Database::fetch("SELECT b.*, p.name AS parish_name, p.city AS parish_city, p.state AS parish_state
                          FROM occi_baptisms b
                          LEFT JOIN occi_parishes p ON p.id = b.parish_id
                          WHERE b.id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Record not found.'); }

    $certHeader = '';
    $ch = Database::fetch("SELECT value FROM settings WHERE `key` = 'nsr_cert_header' LIMIT 1");
    if ($ch) $certHeader = $ch['value'];

    $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);
    $location = $r['parish_name'] ? h($r['parish_name']) . ', ' . h($r['parish_city']) . ', ' . h($r['parish_state']) : h($r['alt_location']);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Baptism Certificate &mdash; <?= h($fullName) ?></title>
<style>
  body { font-family: Georgia, serif; margin: 0; padding: 40px 60px; color: #1a1a1a; font-size: 14px; }
  .letterhead { text-align: center; border-bottom: 2px solid #5c3d1e; padding-bottom: 20px; margin-bottom: 30px; }
  .letterhead h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: 1px; text-transform: uppercase; }
  .letterhead h2 { font-size: 14px; font-weight: normal; margin: 0; color: #555; }
  .cert-title { text-align: center; font-size: 18px; font-weight: bold; text-transform: uppercase;
                letter-spacing: 2px; margin: 0 0 24px; }
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
  table.data th { text-align: left; color: #666; font-weight: normal; font-size: 12px;
                  width: 200px; padding: 6px 10px 6px 0; vertical-align: top; }
  table.data td { padding: 6px 0; border-bottom: 1px solid #e8e0d4; font-size: 14px; }
  .section-head { font-size: 12px; font-weight: bold; text-transform: uppercase; color: #5c3d1e;
                   letter-spacing: 1px; border-bottom: 1px solid #5c3d1e; margin: 24px 0 10px;
                   padding-bottom: 4px; }
  .signatures { display: flex; gap: 60px; margin-top: 50px; }
  .sig-line { flex: 1; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; color: #555; }
  .footer { text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ccc;
            margin-top: 40px; padding-top: 12px; font-style: italic; }
  .confidential { color: #8b0000; font-weight: bold; }
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

<div class="cert-title">Certificate of Baptism</div>

<?php if ($r['is_confidential']): ?>
<p style="text-align:center; color:#8b0000; font-size:12px; margin-bottom:20px;">
  <strong>CONFIDENTIAL RECORD</strong> &mdash; Restricted Access
</p>
<?php endif; ?>

<div class="section-head">Person Baptized</div>
<table class="data">
  <tr><th>Full Name</th><td><?= h($fullName) ?></td></tr>
  <tr><th>Date of Birth</th><td><?= $r['birth_date'] ? date('F j, Y', strtotime($r['birth_date'])) : '&mdash;' ?></td></tr>
  <tr><th>Place of Birth</th><td><?= $r['birth_place'] ? h($r['birth_place']) : '&mdash;' ?></td></tr>
</table>

<div class="section-head">Baptism Details</div>
<table class="data">
  <tr><th>Date of Baptism</th><td><?= date('F j, Y', strtotime($r['baptism_date'])) ?></td></tr>
  <tr><th>Location</th><td><?= $location ?></td></tr>
  <?php if ($r['record_book']): ?>
  <tr><th>Record Book</th><td><?= h($r['record_book']) ?></td></tr>
  <?php endif; ?>
  <?php if ($r['page_number']): ?>
  <tr><th>Page Number</th><td><?= h($r['page_number']) ?></td></tr>
  <?php endif; ?>
</table>

<div class="section-head">Family</div>
<table class="data">
  <tr><th>Father</th><td><?= h(trim($r['father_first_name'] . ' ' . $r['father_middle_name'] . ' ' . $r['father_last_name'])) ?: '&mdash;' ?></td></tr>
  <tr><th>Mother</th><td><?= h(trim($r['mother_first_name'] . ' ' . $r['mother_middle_name'] . ' ' . $r['mother_last_name'])) ?: '&mdash;' ?>
    <?php if ($r['mother_maiden_name']): ?> (n&eacute;e <?= h($r['mother_maiden_name']) ?>)<?php endif; ?></td></tr>
</table>

<?php if ($r['sponsor1_name'] || $r['sponsor2_name']): ?>
<div class="section-head">Sponsors</div>
<table class="data">
  <?php if ($r['sponsor1_name']): ?>
  <tr>
    <th>Sponsor 1</th>
    <td><?= h($r['sponsor1_name']) ?>
      <?php if ($r['sponsor1_gender']): ?>(<?= $r['sponsor1_gender'] === 'M' ? 'Godfather' : 'Godmother' ?>)<?php endif; ?>
      <?php if ($r['sponsor1_is_proxy']): ?> &mdash; Proxy for <?= h($r['sponsor1_proxy_for']) ?><?php endif; ?>
    </td>
  </tr>
  <?php endif; ?>
  <?php if ($r['sponsor2_name']): ?>
  <tr>
    <th>Sponsor 2</th>
    <td><?= h($r['sponsor2_name']) ?>
      <?php if ($r['sponsor2_gender']): ?>(<?= $r['sponsor2_gender'] === 'M' ? 'Godfather' : 'Godmother' ?>)<?php endif; ?>
      <?php if ($r['sponsor2_is_proxy']): ?> &mdash; Proxy for <?= h($r['sponsor2_proxy_for']) ?><?php endif; ?>
    </td>
  </tr>
  <?php endif; ?>
</table>
<?php endif; ?>

<div class="section-head">Minister</div>
<table class="data">
  <tr><th>Minister</th><td><?= h($r['minister_name']) ?></td></tr>
  <tr><th>Type</th><td><?= h($r['minister_type']) ?></td></tr>
</table>

<?php if ($r['notations']): ?>
<div class="section-head">Notations</div>
<p style="margin:0; font-size:13px;"><?= nl2br(h($r['notations'])) ?></p>
<?php endif; ?>

<div class="signatures">
  <div class="sig-line">Minister / Officiant</div>
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
// POST: save or delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    if (($_POST['_action'] ?? '') === 'delete') {
        Auth::requirePermission('edit_records');
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::query("DELETE FROM occi_baptisms WHERE id = ?", [$delId]);
            flash('success', 'Record deleted.');
        }
        redirect(siteUrl('admin/records/baptisms'));
    }

    if (($_POST['_action'] ?? '') === 'save') {
        Auth::requirePermission('edit_records');

        $fields = [
            'baptism_date'       => $_POST['baptism_date']       ?? '',
            'birth_date'         => ($_POST['birth_date'] ?? '') ?: null,
            'birth_place'        => $_POST['birth_place']        ?? '',
            'first_name'         => trim($_POST['first_name']    ?? ''),
            'middle_name'        => trim($_POST['middle_name']   ?? ''),
            'last_name'          => trim($_POST['last_name']     ?? ''),
            'father_first_name'  => trim($_POST['father_first_name']  ?? ''),
            'father_middle_name' => trim($_POST['father_middle_name'] ?? ''),
            'father_last_name'   => trim($_POST['father_last_name']   ?? ''),
            'mother_first_name'  => trim($_POST['mother_first_name']  ?? ''),
            'mother_middle_name' => trim($_POST['mother_middle_name'] ?? ''),
            'mother_last_name'   => trim($_POST['mother_last_name']   ?? ''),
            'mother_maiden_name' => trim($_POST['mother_maiden_name'] ?? ''),
            'sponsor1_name'      => trim($_POST['sponsor1_name']      ?? ''),
            'sponsor1_gender'    => $_POST['sponsor1_gender']    ?? '',
            'sponsor1_is_proxy'  => isset($_POST['sponsor1_is_proxy']) ? 1 : 0,
            'sponsor1_proxy_for' => trim($_POST['sponsor1_proxy_for'] ?? ''),
            'sponsor2_name'      => trim($_POST['sponsor2_name']      ?? ''),
            'sponsor2_gender'    => $_POST['sponsor2_gender']    ?? '',
            'sponsor2_is_proxy'  => isset($_POST['sponsor2_is_proxy']) ? 1 : 0,
            'sponsor2_proxy_for' => trim($_POST['sponsor2_proxy_for'] ?? ''),
            'minister_name'      => trim($_POST['minister_name'] ?? ''),
            'minister_type'      => trim($_POST['minister_type'] ?? ''),
            'parish_id'          => ($_POST['parish_id'] ?? '') ?: null,
            'alt_location'       => trim($_POST['alt_location']  ?? ''),
            'notations'          => trim($_POST['notations']     ?? ''),
            'is_confidential'    => isset($_POST['is_confidential']) ? 1 : 0,
            'record_book'        => trim($_POST['record_book']   ?? ''),
            'page_number'        => trim($_POST['page_number']   ?? ''),
        ];

        if (!$fields['first_name'] || !$fields['last_name'] || !$fields['baptism_date']) {
            flash('error', 'First name, last name, and baptism date are required.');
            redirect(siteUrl('admin/records/baptisms' . ($id ? '/' . $id . '/edit' : '/new')));
        }

        if ($id) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Database::update('occi_baptisms', $fields, 'id = ?', [$id]);
            flash('success', 'Record updated.');
            redirect(siteUrl('admin/records/baptisms/' . $id));
        } else {
            $fields['created_by'] = Auth::id();
            $newId = Database::insert('occi_baptisms', $fields);
            flash('success', 'Record created.');
            redirect(siteUrl('admin/records/baptisms/' . $newId));
        }
    }
}

// ---------------------------------------------------------------
// View single record
// ---------------------------------------------------------------
if ($action === 'view' && $id) {
    $r = Database::fetch("SELECT b.*, p.name AS parish_name, p.city AS parish_city, p.state AS parish_state
                          FROM occi_baptisms b
                          LEFT JOIN occi_parishes p ON p.id = b.parish_id
                          WHERE b.id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Record not found.'); }

    $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);
    adminLayout('Baptism: ' . $fullName, function() use ($r, $id, $fullName) {
    ?>
    <div style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <a href="<?= siteUrl('admin/records/baptisms') ?>" class="btn btn-secondary btn-sm">&larr; All Baptisms</a>
      <?php if (Auth::hasPermission('print_certificates')): ?>
      <a href="<?= siteUrl('admin/records/baptisms/' . $id . '/certificate') ?>" target="_blank" class="btn btn-secondary btn-sm">&#128196; Print Certificate</a>
      <?php endif; ?>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <a href="<?= siteUrl('admin/records/baptisms/' . $id . '/edit') ?>" class="btn btn-primary btn-sm">Edit Record</a>
      <?php endif; ?>
    </div>

    <?php if ($r['is_confidential']): ?>
    <div class="alert alert-error" style="font-size:13px;">Confidential Record &mdash; Restricted Access</div>
    <?php endif; ?>

    <div class="card" style="max-width:820px;">
      <div class="card-header"><h2 class="card-title"><?= h($fullName) ?></h2></div>
      <table style="width:100%; font-size:14px; border-collapse:collapse;">
        <tr><td style="padding:7px 0; color:#888; width:200px;">Baptism Date</td><td><?= h($r['baptism_date']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Birth Date</td><td><?= $r['birth_date'] ? h($r['birth_date']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Birth Place</td><td><?= $r['birth_place'] ? h($r['birth_place']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Record Book</td><td><?= $r['record_book'] ? h($r['record_book']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Page Number</td><td><?= $r['page_number'] ? h($r['page_number']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Father</td><td><?= h(trim($r['father_first_name'] . ' ' . $r['father_middle_name'] . ' ' . $r['father_last_name'])) ?: '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Mother</td><td><?= h(trim($r['mother_first_name'] . ' ' . $r['mother_middle_name'] . ' ' . $r['mother_last_name'])) ?: '&mdash;' ?><?= $r['mother_maiden_name'] ? ' (n&eacute;e ' . h($r['mother_maiden_name']) . ')' : '' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Sponsor 1</td><td><?= $r['sponsor1_name'] ? h($r['sponsor1_name']) . ($r['sponsor1_gender'] ? ' (' . ($r['sponsor1_gender'] === 'M' ? 'Godfather' : 'Godmother') . ')' : '') . ($r['sponsor1_is_proxy'] ? ' &mdash; Proxy for ' . h($r['sponsor1_proxy_for']) : '') : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Sponsor 2</td><td><?= $r['sponsor2_name'] ? h($r['sponsor2_name']) . ($r['sponsor2_gender'] ? ' (' . ($r['sponsor2_gender'] === 'M' ? 'Godfather' : 'Godmother') . ')' : '') . ($r['sponsor2_is_proxy'] ? ' &mdash; Proxy for ' . h($r['sponsor2_proxy_for']) : '') : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Minister</td><td><?= h($r['minister_name']) ?> <?= $r['minister_type'] ? '(' . h($r['minister_type']) . ')' : '' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Location</td><td><?= $r['parish_name'] ? h($r['parish_name']) . ', ' . h($r['parish_city']) . ', ' . h($r['parish_state']) : ($r['alt_location'] ? h($r['alt_location']) : '&mdash;') ?></td></tr>
        <?php if ($r['notations']): ?>
        <tr><td style="padding:7px 0; color:#888; vertical-align:top;">Notations</td><td><?= nl2br(h($r['notations'])) ?></td></tr>
        <?php endif; ?>
      </table>

      <?php if (Auth::hasPermission('edit_records')): ?>
      <div style="margin-top:20px; padding-top:16px; border-top:1px solid #eee;">
        <form method="post" style="display:inline;"
              onsubmit="return confirm('Permanently delete this record?')">
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

    $r = ['baptism_date'=>'','birth_date'=>'','birth_place'=>'','first_name'=>'','middle_name'=>'','last_name'=>'',
          'father_first_name'=>'','father_middle_name'=>'','father_last_name'=>'',
          'mother_first_name'=>'','mother_middle_name'=>'','mother_last_name'=>'','mother_maiden_name'=>'',
          'sponsor1_name'=>'','sponsor1_gender'=>'','sponsor1_is_proxy'=>0,'sponsor1_proxy_for'=>'',
          'sponsor2_name'=>'','sponsor2_gender'=>'','sponsor2_is_proxy'=>0,'sponsor2_proxy_for'=>'',
          'minister_name'=>$defaultMinister,'minister_type'=>'','parish_id'=>'','alt_location'=>'',
          'notations'=>'','is_confidential'=>0,'record_book'=>'','page_number'=>''];

    if ($action === 'edit' && $id) {
        $row = Database::fetch("SELECT * FROM occi_baptisms WHERE id = ?", [$id]);
        if (!$row) { http_response_code(404); die('Record not found.'); }
        $r = $row;
    }

    $pageTitle = $action === 'new' ? 'New Baptism Record' : 'Edit Baptism Record';
    adminLayout($pageTitle, function() use ($r, $id, $action, $parishes) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/records/baptisms' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary btn-sm">&larr; Cancel</a>
    </div>

    <form method="post" action="<?= siteUrl('admin/records/baptisms' . ($id ? '/' . $id . '/edit' : '/new')) ?>" style="max-width:820px;">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="save">

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Baptism Details</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Baptism Date <span style="color:#dc3545;">*</span></label>
            <input type="date" name="baptism_date" value="<?= h($r['baptism_date']) ?>" required class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Birth Date</label>
            <input type="date" name="birth_date" value="<?= h($r['birth_date'] ?? '') ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Birth Place</label>
            <input type="text" name="birth_place" value="<?= h($r['birth_place']) ?>" class="form-control">
          </div>
          <div></div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Record Book</label>
            <input type="text" name="record_book" value="<?= h($r['record_book']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Page Number</label>
            <input type="text" name="page_number" value="<?= h($r['page_number']) ?>" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Person Baptized</h3></div>
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
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Father</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">First Name</label>
            <input type="text" name="father_first_name" value="<?= h($r['father_first_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Middle Name</label>
            <input type="text" name="father_middle_name" value="<?= h($r['father_middle_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Last Name</label>
            <input type="text" name="father_last_name" value="<?= h($r['father_last_name']) ?>" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Mother</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">First Name</label>
            <input type="text" name="mother_first_name" value="<?= h($r['mother_first_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Middle Name</label>
            <input type="text" name="mother_middle_name" value="<?= h($r['mother_middle_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Last Name</label>
            <input type="text" name="mother_last_name" value="<?= h($r['mother_last_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Maiden Name</label>
            <input type="text" name="mother_maiden_name" value="<?= h($r['mother_maiden_name']) ?>" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Sponsors</h3></div>
        <div style="padding:4px 0;">
          <p style="font-size:13px; color:#666; margin:0 0 12px;">Sponsor 1</p>
          <div style="display:grid; grid-template-columns:2fr 1fr 1fr 2fr; gap:14px; margin-bottom:12px;">
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Name</label>
              <input type="text" name="sponsor1_name" value="<?= h($r['sponsor1_name']) ?>" class="form-control">
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Gender</label>
              <select name="sponsor1_gender" class="form-control">
                <option value="">--</option>
                <option value="M" <?= $r['sponsor1_gender'] === 'M' ? 'selected' : '' ?>>Godfather (M)</option>
                <option value="F" <?= $r['sponsor1_gender'] === 'F' ? 'selected' : '' ?>>Godmother (F)</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">By Proxy?</label>
              <label style="display:flex; align-items:center; gap:6px; margin-top:8px;">
                <input type="checkbox" name="sponsor1_is_proxy" value="1" <?= $r['sponsor1_is_proxy'] ? 'checked' : '' ?>>
                <span style="font-size:13px;">Yes</span>
              </label>
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Proxy For</label>
              <input type="text" name="sponsor1_proxy_for" value="<?= h($r['sponsor1_proxy_for']) ?>" class="form-control">
            </div>
          </div>
          <p style="font-size:13px; color:#666; margin:12px 0 12px;">Sponsor 2</p>
          <div style="display:grid; grid-template-columns:2fr 1fr 1fr 2fr; gap:14px;">
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Name</label>
              <input type="text" name="sponsor2_name" value="<?= h($r['sponsor2_name']) ?>" class="form-control">
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Gender</label>
              <select name="sponsor2_gender" class="form-control">
                <option value="">--</option>
                <option value="M" <?= $r['sponsor2_gender'] === 'M' ? 'selected' : '' ?>>Godfather (M)</option>
                <option value="F" <?= $r['sponsor2_gender'] === 'F' ? 'selected' : '' ?>>Godmother (F)</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">By Proxy?</label>
              <label style="display:flex; align-items:center; gap:6px; margin-top:8px;">
                <input type="checkbox" name="sponsor2_is_proxy" value="1" <?= $r['sponsor2_is_proxy'] ? 'checked' : '' ?>>
                <span style="font-size:13px;">Yes</span>
              </label>
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Proxy For</label>
              <input type="text" name="sponsor2_proxy_for" value="<?= h($r['sponsor2_proxy_for']) ?>" class="form-control">
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Minister</h3></div>
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Minister Name</label>
            <input type="text" name="minister_name" value="<?= h($r['minister_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Minister Type</label>
            <input type="text" name="minister_type" value="<?= h($r['minister_type']) ?>" placeholder="e.g. Priest, Deacon" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Location</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; padding:4px 0;">
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
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Alt. Location (if no parish)</label>
            <input type="text" name="alt_location" value="<?= h($r['alt_location']) ?>" class="form-control">
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Notations</h3></div>
        <div style="padding:4px 0;">
          <textarea name="notations" rows="4" class="form-control" style="width:100%; box-sizing:border-box;"><?= h($r['notations']) ?></textarea>
          <label style="display:flex; align-items:center; gap:8px; margin-top:10px; font-size:13px;">
            <input type="checkbox" name="is_confidential" value="1" <?= $r['is_confidential'] ? 'checked' : '' ?>>
            Mark as Confidential
          </label>
        </div>
      </div>

      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary">Save Record</button>
        <a href="<?= siteUrl('admin/records/baptisms' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary">Cancel</a>
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
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_baptisms WHERE first_name LIKE ? OR last_name LIKE ? OR minister_name LIKE ?", [$like, $like, $like])['n'];
    $rows  = Database::fetchAll("SELECT b.*, p.name AS parish_name FROM occi_baptisms b LEFT JOIN occi_parishes p ON p.id = b.parish_id WHERE b.first_name LIKE ? OR b.last_name LIKE ? OR b.minister_name LIKE ? ORDER BY b.baptism_date DESC LIMIT ? OFFSET ?", [$like, $like, $like, $perPage, $offset]);
} else {
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_baptisms")['n'];
    $rows  = Database::fetchAll("SELECT b.*, p.name AS parish_name FROM occi_baptisms b LEFT JOIN occi_parishes p ON p.id = b.parish_id ORDER BY b.baptism_date DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
}

adminLayout('Baptisms', function() use ($rows, $total, $page, $perPage, $search) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <form method="get" style="display:flex; gap:8px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search name or minister..." class="form-control" style="width:260px;">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($search): ?><a href="<?= siteUrl('admin/records/baptisms') ?>" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
  </form>
  <?php if (Auth::hasPermission('edit_records')): ?>
  <a href="<?= siteUrl('admin/records/baptisms/new') ?>" class="btn btn-primary btn-sm">+ New Baptism</a>
  <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">No baptism records found.</div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Baptism Date</th>
          <th>Parish</th>
          <th>Minister</th>
          <th>Confidential</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td style="font-size:13px;"><?= h($r['baptism_date']) ?></td>
          <td style="font-size:13px;"><?= $r['parish_name'] ? h($r['parish_name']) : '<span style="color:#aaa;">--</span>' ?></td>
          <td style="font-size:13px;"><?= $r['minister_name'] ? h($r['minister_name']) : '<span style="color:#aaa;">--</span>' ?></td>
          <td style="font-size:13px; text-align:center;"><?= $r['is_confidential'] ? '&#128274;' : '' ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= siteUrl('admin/records/baptisms/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $page, $perPage, siteUrl('admin/records/baptisms?' . ($search ? 'q=' . urlencode($search) . '&' : ''))) ?>
<?php endif; ?>
<?php });
