<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

if (!Database::fetch("SHOW TABLES LIKE 'occi_deaths'")) {
    adminLayout('Deaths', function() { ?>
      <div class="alert alert-error">NSR tables are missing. Please run pending migrations under
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>.</div>
    <?php });
    return;
}

$id     = (int)($_GET['id']     ?? 0);
$action = $_GET['action'] ?? ($id ? 'view' : 'list');
$parishes = Database::fetchAll("SELECT id, name, city, state FROM occi_parishes ORDER BY name ASC");

// ---------------------------------------------------------------
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    if (($_POST['_action'] ?? '') === 'delete') {
        Auth::requirePermission('edit_records');
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::query("DELETE FROM occi_deaths WHERE id = ?", [$delId]);
            flash('success', 'Record deleted.');
        }
        redirect(siteUrl('admin/records/deaths'));
    }

    if (($_POST['_action'] ?? '') === 'save') {
        Auth::requirePermission('edit_records');
        $fields = [
            'death_date'            => $_POST['death_date']           ?? '',
            'first_name'            => trim($_POST['first_name']       ?? ''),
            'middle_name'           => trim($_POST['middle_name']      ?? ''),
            'last_name'             => trim($_POST['last_name']        ?? ''),
            'burial_location'       => trim($_POST['burial_location']  ?? ''),
            'burial_city'           => trim($_POST['burial_city']      ?? ''),
            'burial_state'          => trim($_POST['burial_state']     ?? ''),
            'funeral_date'          => ($_POST['funeral_date']         ?? '') ?: null,
            'funeral_presider'      => trim($_POST['funeral_presider'] ?? ''),
            'parish_id'             => ($_POST['parish_id']            ?? '') ?: null,
            'is_graveside'          => isset($_POST['is_graveside'])   ? 1 : 0,
            'cemetery_name'         => trim($_POST['cemetery_name']    ?? ''),
            'cemetery_city'         => trim($_POST['cemetery_city']    ?? ''),
            'cemetery_state'        => trim($_POST['cemetery_state']   ?? ''),
            'is_cremated'           => isset($_POST['is_cremated'])    ? 1 : 0,
            'ashes_interment_date'  => ($_POST['ashes_interment_date'] ?? '') ?: null,
            'ashes_interment_place' => trim($_POST['ashes_interment_place'] ?? ''),
            'notations'             => trim($_POST['notations']        ?? ''),
        ];

        if (!$fields['first_name'] || !$fields['last_name'] || !$fields['death_date']) {
            flash('error', 'First name, last name, and death date are required.');
            redirect(siteUrl('admin/records/deaths' . ($id ? '/' . $id . '/edit' : '/new')));
        }

        if ($id) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Database::update('occi_deaths', $fields, 'id = ?', [$id]);
            flash('success', 'Record updated.');
            redirect(siteUrl('admin/records/deaths/' . $id));
        } else {
            $newId = Database::insert('occi_deaths', $fields);
            flash('success', 'Record created.');
            redirect(siteUrl('admin/records/deaths/' . $newId));
        }
    }
}

// ---------------------------------------------------------------
// View
// ---------------------------------------------------------------
if ($action === 'view' && $id) {
    $r = Database::fetch("SELECT d.*, p.name AS parish_name, p.city AS parish_city, p.state AS parish_state
                          FROM occi_deaths d LEFT JOIN occi_parishes p ON p.id = d.parish_id
                          WHERE d.id = ?", [$id]);
    if (!$r) { http_response_code(404); die('Record not found.'); }
    $fullName = trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);

    adminLayout('Death Record: ' . $fullName, function() use ($r, $id, $fullName) {
    ?>
    <div style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <a href="<?= siteUrl('admin/records/deaths') ?>" class="btn btn-secondary btn-sm">&larr; All Deaths</a>
      <?php if (Auth::hasPermission('edit_records')): ?>
      <a href="<?= siteUrl('admin/records/deaths/' . $id . '/edit') ?>" class="btn btn-primary btn-sm">Edit Record</a>
      <?php endif; ?>
    </div>
    <div class="card" style="max-width:820px;">
      <div class="card-header"><h2 class="card-title"><?= h($fullName) ?></h2></div>
      <table style="width:100%; font-size:14px; border-collapse:collapse;">
        <tr><td style="padding:7px 0; color:#888; width:220px;">Death Date</td><td><?= h($r['death_date']) ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Funeral Date</td><td><?= $r['funeral_date'] ? h($r['funeral_date']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Funeral Presider</td><td><?= $r['funeral_presider'] ? h($r['funeral_presider']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Service Type</td><td><?= $r['is_graveside'] ? 'Graveside Service' : 'Church Service' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Burial Location</td><td><?= $r['burial_location'] ? h($r['burial_location']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Burial City / State</td><td><?= ($r['burial_city'] || $r['burial_state']) ? h($r['burial_city']) . ', ' . h($r['burial_state']) : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Cemetery</td><td><?= $r['cemetery_name'] ? h($r['cemetery_name']) . ($r['cemetery_city'] ? ', ' . h($r['cemetery_city']) . ', ' . h($r['cemetery_state']) : '') : '&mdash;' ?></td></tr>
        <tr><td style="padding:7px 0; color:#888;">Cremated</td><td><?= $r['is_cremated'] ? 'Yes' : 'No' ?></td></tr>
        <?php if ($r['is_cremated']): ?>
        <tr><td style="padding:7px 0; color:#888;">Ashes Interment</td><td><?= $r['ashes_interment_date'] ? h($r['ashes_interment_date']) : '&mdash;' ?><?= $r['ashes_interment_place'] ? ' at ' . h($r['ashes_interment_place']) : '' ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding:7px 0; color:#888;">Parish</td><td><?= $r['parish_name'] ? h($r['parish_name']) . ', ' . h($r['parish_city']) . ', ' . h($r['parish_state']) : '&mdash;' ?></td></tr>
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
    $r = ['death_date'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','burial_location'=>'','burial_city'=>'','burial_state'=>'',
          'funeral_date'=>'','funeral_presider'=>'','parish_id'=>'','is_graveside'=>0,'cemetery_name'=>'','cemetery_city'=>'','cemetery_state'=>'',
          'is_cremated'=>0,'ashes_interment_date'=>'','ashes_interment_place'=>'','notations'=>''];
    if ($action === 'edit' && $id) {
        $row = Database::fetch("SELECT * FROM occi_deaths WHERE id = ?", [$id]);
        if (!$row) { http_response_code(404); die('Record not found.'); }
        $r = $row;
    }
    $pageTitle = $action === 'new' ? 'New Death Record' : 'Edit Death Record';
    adminLayout($pageTitle, function() use ($r, $id, $parishes) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/records/deaths' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary btn-sm">&larr; Cancel</a>
    </div>
    <form method="post" action="<?= siteUrl('admin/records/deaths' . ($id ? '/' . $id . '/edit' : '/new')) ?>" style="max-width:820px;">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="save">

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Deceased</h3></div>
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
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Death &amp; Funeral</h3></div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; padding:4px 0;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Death Date <span style="color:#dc3545;">*</span></label>
            <input type="date" name="death_date" value="<?= h($r['death_date']) ?>" required class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Funeral Date</label>
            <input type="date" name="funeral_date" value="<?= h($r['funeral_date'] ?? '') ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Funeral Presider</label>
            <input type="text" name="funeral_presider" value="<?= h($r['funeral_presider']) ?>" class="form-control">
          </div>
        </div>
        <div style="margin-top:12px;">
          <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
            <input type="checkbox" name="is_graveside" value="1" <?= $r['is_graveside'] ? 'checked' : '' ?>>
            Graveside Service (not a church service)
          </label>
        </div>
        <div style="margin-top:12px;">
          <label style="display:block; font-size:13px; margin-bottom:4px;">Parish</label>
          <select name="parish_id" class="form-control" style="max-width:400px;">
            <option value="">-- Select Parish --</option>
            <?php foreach ($parishes as $p): ?>
            <option value="<?= $p['id'] ?>" <?= (string)($r['parish_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['name']) ?>, <?= h($p['city']) ?>, <?= h($p['state']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Burial</h3></div>
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px; padding:4px 0 12px;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Burial Location</label>
            <input type="text" name="burial_location" value="<?= h($r['burial_location']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Burial City</label>
            <input type="text" name="burial_city" value="<?= h($r['burial_city']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Burial State</label>
            <input type="text" name="burial_state" value="<?= h($r['burial_state']) ?>" class="form-control">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px; padding:0 0 12px;">
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Cemetery Name</label>
            <input type="text" name="cemetery_name" value="<?= h($r['cemetery_name']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Cemetery City</label>
            <input type="text" name="cemetery_city" value="<?= h($r['cemetery_city']) ?>" class="form-control">
          </div>
          <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">Cemetery State</label>
            <input type="text" name="cemetery_state" value="<?= h($r['cemetery_state']) ?>" class="form-control">
          </div>
        </div>
        <div>
          <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:12px;">
            <input type="checkbox" name="is_cremated" value="1" id="is_cremated" <?= $r['is_cremated'] ? 'checked' : '' ?>>
            Cremated
          </label>
          <div style="display:grid; grid-template-columns:1fr 2fr; gap:14px;">
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Ashes Interment Date</label>
              <input type="date" name="ashes_interment_date" value="<?= h($r['ashes_interment_date'] ?? '') ?>" class="form-control">
            </div>
            <div>
              <label style="display:block; font-size:13px; margin-bottom:4px;">Ashes Interment Place</label>
              <input type="text" name="ashes_interment_place" value="<?= h($r['ashes_interment_place']) ?>" class="form-control">
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h3 class="card-title" style="font-size:15px;">Notations</h3></div>
        <textarea name="notations" rows="4" class="form-control" style="width:100%; box-sizing:border-box;"><?= h($r['notations']) ?></textarea>
      </div>

      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary">Save Record</button>
        <a href="<?= siteUrl('admin/records/deaths' . ($id ? '/' . $id : '')) ?>" class="btn btn-secondary">Cancel</a>
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
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_deaths WHERE first_name LIKE ? OR last_name LIKE ?", [$like, $like])['n'];
    $rows  = Database::fetchAll("SELECT d.*, p.name AS parish_name FROM occi_deaths d LEFT JOIN occi_parishes p ON p.id = d.parish_id WHERE d.first_name LIKE ? OR d.last_name LIKE ? ORDER BY d.death_date DESC LIMIT ? OFFSET ?", [$like, $like, $perPage, $offset]);
} else {
    $total = Database::fetch("SELECT COUNT(*) AS n FROM occi_deaths")['n'];
    $rows  = Database::fetchAll("SELECT d.*, p.name AS parish_name FROM occi_deaths d LEFT JOIN occi_parishes p ON p.id = d.parish_id ORDER BY d.death_date DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
}

adminLayout('Deaths', function() use ($rows, $total, $page, $perPage, $search) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <form method="get" style="display:flex; gap:8px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by name..." class="form-control" style="width:240px;">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($search): ?><a href="<?= siteUrl('admin/records/deaths') ?>" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
  </form>
  <?php if (Auth::hasPermission('edit_records')): ?>
  <a href="<?= siteUrl('admin/records/deaths/new') ?>" class="btn btn-primary btn-sm">+ New Death Record</a>
  <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
  <div class="card" style="text-align:center; padding:40px; color:#aaa;">No death records found.</div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Death Date</th><th>Funeral Presider</th><th>Parish</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td style="font-size:13px;"><?= h($r['death_date']) ?></td>
          <td style="font-size:13px;"><?= $r['funeral_presider'] ? h($r['funeral_presider']) : '<span style="color:#aaa;">--</span>' ?></td>
          <td style="font-size:13px;"><?= $r['parish_name'] ? h($r['parish_name']) : '<span style="color:#aaa;">--</span>' ?></td>
          <td><a href="<?= siteUrl('admin/records/deaths/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $page, $perPage, siteUrl('admin/records/deaths?' . ($search ? 'q=' . urlencode($search) . '&' : ''))) ?>
<?php endif; ?>
<?php });
