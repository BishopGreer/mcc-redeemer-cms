<?php
require_once __DIR__ . '/layout.php';

Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_content');

if (Database::siteId() !== 1) {
    adminLayout('Clergy Directory', fn() => print(
        '<p style="color:var(--danger);">The Clergy Directory is only available on the main site.</p>'
    ));
    return;
}

// ─── Constants ───────────────────────────────────────────────────────────────

$titleOptions = [
    ''               => '— None —',
    'The Right Rev.' => 'The Right Rev.',
    'The Very Rev.'  => 'The Very Rev.',
    'Rev. Fr.'       => 'Rev. Fr.',
    'Rev. Mthr.'     => 'Rev. Mthr.',
    'Rev. Mrs.'      => 'Rev. Mrs.',
    'Rev. Mr.'       => 'Rev. Mr.',
    'Mr.'            => 'Mr.',
    'Mrs.'           => 'Mrs.',
    'other'          => 'Other (specify below)',
];

$positionLabels = [
    'presiding_bishop' => 'Presiding Bishop',
    'chancellor'       => 'Chancellor',
    'bishop'           => 'Bishop',
    'monsignor'        => 'Monsignor',
    'priest'           => 'Priest',
    'deacon'           => 'Deacon',
    'subdeacon'        => 'SubDeacon',
    'religious'        => 'Religious',
    'seminarian'       => 'Seminarian',
    'candidate'        => 'Candidate',
    'laity'            => 'Laity',
];

$socialFields = [
    'social_facebook'  => 'Facebook',
    'social_instagram' => 'Instagram',
    'social_twitter'   => 'X (Twitter)',
    'social_threads'   => 'Threads',
    'social_youtube'   => 'YouTube',
    'social_tiktok'    => 'TikTok',
    'social_linkedin'  => 'LinkedIn',
    'social_bluesky'   => 'BlueSky',
    'social_pinterest' => 'Pinterest',
    'social_snapchat'  => 'Snapchat',
    'social_mastodon'  => 'Mastodon',
    'social_pixelfed'  => 'Pixelfed',
];

// Table guard
if (!Database::fetch("SHOW TABLES LIKE 'clergy'")) {
    adminLayout('Clergy Directory', function() { ?>
      <div class="alert alert-error">
        The <strong>clergy</strong> table is missing. Go to
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
        and run pending migrations, then return here.
      </div>
    <?php });
    return;
}

$uploadDir = BASE_PATH . '/public/uploads/clergy/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Shared input style
$inp = 'width:100%; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px; box-sizing:border-box;';
$lbl = 'display:block; font-size:13px; color:#555; margin-bottom:3px; font-weight:600;';

// ─── POST handlers ───────────────────────────────────────────────────────────

$errors   = [];
$existing = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $act = $_POST['_action'] ?? '';
    $pid = (int)($_POST['id'] ?? 0);

    // Delete
    if ($act === 'delete' && $pid) {
        $row = Database::fetch("SELECT photo FROM clergy WHERE id = ?", [$pid]);
        if ($row && $row['photo']) {
            @unlink(BASE_PATH . '/public/' . $row['photo']);
        }
        Database::delete('clergy', 'id = ?', [$pid]);
        flash('success', 'Clergy record deleted.');
        redirect(siteUrl('admin/clergy'));
    }

    // Save
    if ($act === 'save') {
        $existing  = $pid ? (Database::fetch("SELECT * FROM clergy WHERE id = ?", [$pid]) ?: []) : [];
        $photoPath = $existing['photo'] ?? null;

        // Remove photo
        if (!empty($_POST['remove_photo']) && $photoPath) {
            @unlink(BASE_PATH . '/public/' . $photoPath);
            $photoPath = null;
        }

        // Upload photo
        if (!empty($_FILES['photo']['name'])) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mime    = mime_content_type($_FILES['photo']['tmp_name']);
            if (!in_array($mime, $allowed)) {
                $errors[] = 'Photo must be a JPEG, PNG, GIF, or WebP image.';
            } elseif ($_FILES['photo']['size'] > 8 * 1024 * 1024) {
                $errors[] = 'Photo must be under 8 MB.';
            } else {
                $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $filename = 'clergy_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                    if ($photoPath) @unlink(BASE_PATH . '/public/' . $photoPath);
                    $photoPath = 'uploads/clergy/' . $filename;
                } else {
                    $errors[] = 'Photo could not be saved. Check permissions on public/uploads/clergy/.';
                }
            }
        }

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        if (!$firstName && !$lastName) {
            $errors[] = 'First or last name is required.';
        }

        if (empty($errors)) {
            $titlePrefix = $_POST['title_prefix'] ?? '';
            $data = [
                'photo'            => $photoPath,
                'title_prefix'     => $titlePrefix,
                'title_custom'     => $titlePrefix === 'other' ? trim($_POST['title_custom'] ?? '') : null,
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'religious_order'  => trim($_POST['religious_order']  ?? '') ?: null,
                'address_line1'    => trim($_POST['address_line1']    ?? '') ?: null,
                'address_line2'    => trim($_POST['address_line2']    ?? '') ?: null,
                'city'             => trim($_POST['city']             ?? '') ?: null,
                'state_province'   => trim($_POST['state_province']   ?? '') ?: null,
                'postal_code'      => trim($_POST['postal_code']      ?? '') ?: null,
                'country'          => trim($_POST['country']          ?? '') ?: null,
                'phone'            => trim($_POST['phone']            ?? '') ?: null,
                'email'            => trim($_POST['email']            ?? '') ?: null,
                'parish'           => trim($_POST['parish']           ?? '') ?: null,
                'parish_url'       => trim($_POST['parish_url']       ?? '') ?: null,
                'diocese'          => trim($_POST['diocese']          ?? '') ?: null,
                'diocese_url'      => trim($_POST['diocese_url']      ?? '') ?: null,
                'office'           => trim($_POST['office']           ?? '') ?: null,
                'office_url'       => trim($_POST['office_url']       ?? '') ?: null,
                'position'         => $_POST['position'] ?? 'laity',
                'social_facebook'  => trim($_POST['social_facebook']  ?? '') ?: null,
                'social_instagram' => trim($_POST['social_instagram'] ?? '') ?: null,
                'social_twitter'   => trim($_POST['social_twitter']   ?? '') ?: null,
                'social_threads'   => trim($_POST['social_threads']   ?? '') ?: null,
                'social_youtube'   => trim($_POST['social_youtube']   ?? '') ?: null,
                'social_tiktok'    => trim($_POST['social_tiktok']    ?? '') ?: null,
                'social_linkedin'  => trim($_POST['social_linkedin']  ?? '') ?: null,
                'social_bluesky'   => trim($_POST['social_bluesky']   ?? '') ?: null,
                'social_pinterest' => trim($_POST['social_pinterest'] ?? '') ?: null,
                'social_snapchat'  => trim($_POST['social_snapchat']  ?? '') ?: null,
                'social_mastodon'  => trim($_POST['social_mastodon']  ?? '') ?: null,
                'social_pixelfed'  => trim($_POST['social_pixelfed']  ?? '') ?: null,
                'status'           => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                'menu_order'       => (int)($_POST['menu_order'] ?? 0),
            ];

            if ($pid) {
                Database::update('clergy', $data, 'id = ?', [$pid]);
                flash('success', 'Clergy record updated.');
            } else {
                Database::insert('clergy', $data);
                flash('success', 'Clergy record added.');
            }
            redirect(siteUrl('admin/clergy'));
        }

        // Re-populate form on validation error
        $existing = array_merge($existing, $_POST);
        $existing['photo'] = $photoPath;
    }
}

// ─── GET: list ───────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'list') {
    $posOrder  = implode("','", array_keys($positionLabels));
    $allClergy = Database::fetchAll(
        "SELECT * FROM clergy ORDER BY FIELD(position, '{$posOrder}'), menu_order ASC, last_name ASC, first_name ASC"
    );

    adminLayout('Clergy Directory', function() use ($allClergy, $positionLabels) { ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
  <p style="margin:0; color:#666; font-size:13px;"><?= count($allClergy) ?> clergy record(s)</p>
  <div style="display:flex; gap:8px;">
    <a href="<?= siteUrl('clergy-directory') ?>" target="_blank" class="btn btn-secondary btn-sm">&#127760; View Public Page</a>
    <a href="<?= siteUrl('admin/clergy?action=new') ?>" class="btn btn-primary">+ Add Clergy</a>
  </div>
</div>

<?php if (empty($allClergy)): ?>
  <div class="card" style="text-align:center; padding:48px; color:#aaa;">
    No clergy records yet. <a href="<?= siteUrl('admin/clergy?action=new') ?>">Add the first one.</a>
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:56px;">Photo</th>
          <th>Name</th>
          <th>Position</th>
          <th>Parish / Diocese</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allClergy as $c):
          $displayTitle = $c['title_prefix'] === 'other' ? $c['title_custom'] : $c['title_prefix'];
          $fullName = trim(implode(' ', array_filter([$displayTitle, $c['first_name'], $c['last_name']])));
          if ($c['religious_order']) $fullName .= ', ' . $c['religious_order'];
        ?>
        <tr style="<?= $c['status'] === 'inactive' ? 'opacity:.5;' : '' ?>">
          <td>
            <?php if ($c['photo']): ?>
              <img src="<?= siteUrl('public/' . h($c['photo'])) ?>"
                   alt="" style="width:40px; height:50px; object-fit:cover; object-position:center top;
                                 border-radius:3px; display:block;">
            <?php else: ?>
              <div style="width:40px; height:50px; background:#eee; border-radius:3px;
                          display:flex; align-items:center; justify-content:center; font-size:18px; color:#ccc;">&#128100;</div>
            <?php endif; ?>
          </td>
          <td><strong><?= h($fullName) ?></strong></td>
          <td><?= h($positionLabels[$c['position']] ?? $c['position']) ?></td>
          <td style="font-size:13px; color:#666;">
            <?= h($c['parish'] ?? '') ?>
            <?php if ($c['parish'] && $c['diocese']): ?><br><?php endif; ?>
            <?= h($c['diocese'] ?? '') ?>
          </td>
          <td>
            <span style="font-size:12px; padding:2px 8px; border-radius:10px;
                         background:<?= $c['status']==='active' ? '#d4edda' : '#f8d7da' ?>;
                         color:<?= $c['status']==='active' ? '#155724' : '#721c24' ?>;">
              <?= ucfirst(h($c['status'])) ?>
            </span>
          </td>
          <td class="actions">
            <a href="<?= siteUrl('admin/clergy?action=edit&id=' . $c['id']) ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete this clergy record? This cannot be undone.');">
              <?= csrfField() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

    <?php });
    return;
}

// ─── GET / POST: new or edit form ─────────────────────────────────────────────

if ($action === 'edit' && $id && empty($existing)) {
    $existing = Database::fetch("SELECT * FROM clergy WHERE id = ?", [$id]);
    if (!$existing) {
        flash('error', 'Clergy record not found.');
        redirect(siteUrl('admin/clergy'));
    }
}
if ($action === 'new' && empty($existing)) {
    $existing = [];
}

$c         = $existing;
$pageTitle = !empty($c['id']) ? 'Edit Clergy Record' : 'Add Clergy';

adminLayout($pageTitle, function() use ($c, $titleOptions, $positionLabels, $socialFields, $pageTitle, $errors, $inp, $lbl) {
    $v = fn(string $k, string $def = '') => h((string)($c[$k] ?? $def));
?>

<?php if ($errors): ?>
  <div class="alert alert-error">
    <?php foreach ($errors as $e): ?><div>&#9679; <?= h($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <h2 style="margin:0;"><?= h($pageTitle) ?></h2>
  <a href="<?= siteUrl('admin/clergy') ?>" class="btn btn-secondary">&#8592; Back to List</a>
</div>

<form method="post" enctype="multipart/form-data" style="max-width:860px;">
  <?= csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" value="<?= $v('id') ?>">

  <!-- Photo -->
  <div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Photo</h3>

    <?php if (!empty($c['photo'])): ?>
      <div style="display:flex; gap:16px; align-items:flex-start; margin-bottom:14px;">
        <img src="<?= siteUrl('public/' . h($c['photo'])) ?>" alt="Current photo"
             style="width:80px; height:98px; object-fit:cover; object-position:center top;
                    border-radius:4px; border:1px solid #ddd;">
        <div>
          <p style="margin:0 0 8px; font-size:13px; color:#666;">Current photo</p>
          <label style="font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="remove_photo" value="1"> Remove current photo
          </label>
        </div>
      </div>
    <?php endif; ?>

    <label style="<?= $lbl ?>">
      <?= empty($c['photo']) ? 'Upload Photo' : 'Replace Photo' ?>
      <span style="font-weight:400; font-size:12px; color:#888;">(JPEG, PNG, WebP · max 8 MB · portrait orientation recommended)</span>
    </label>
    <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:14px;">
  </div>

  <!-- Name & Title -->
  <div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Name &amp; Title</h3>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
      <div>
        <label style="<?= $lbl ?>">Title / Honorific</label>
        <select name="title_prefix" id="title_prefix" style="<?= $inp ?>"
                onchange="document.getElementById('custom_title_wrap').style.display=this.value==='other'?'block':'none'">
          <?php foreach ($titleOptions as $val => $lbTxt): ?>
            <option value="<?= h($val) ?>" <?= ($c['title_prefix'] ?? '') === $val ? 'selected' : '' ?>>
              <?= h($lbTxt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="custom_title_wrap" style="<?= ($c['title_prefix'] ?? '') === 'other' ? '' : 'display:none;' ?>">
        <label style="<?= $lbl ?>">Custom Title</label>
        <input type="text" name="title_custom" style="<?= $inp ?>"
               value="<?= $v('title_custom') ?>" placeholder="e.g. Dn., Bp., Archbp.">
      </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr 200px; gap:16px;">
      <div>
        <label style="<?= $lbl ?>">First Name *</label>
        <input type="text" name="first_name" style="<?= $inp ?>" value="<?= $v('first_name') ?>" required>
      </div>
      <div>
        <label style="<?= $lbl ?>">Last Name *</label>
        <input type="text" name="last_name" style="<?= $inp ?>" value="<?= $v('last_name') ?>" required>
      </div>
      <div>
        <label style="<?= $lbl ?>">Religious Order <span style="font-weight:400;">(abbrev.)</span></label>
        <input type="text" name="religious_order" style="<?= $inp ?>"
               value="<?= $v('religious_order') ?>" placeholder="OSF, OFM, SJ…">
      </div>
    </div>
  </div>

  <!-- Position & Assignment -->
  <div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Position &amp; Assignment</h3>

    <div style="display:grid; grid-template-columns:1fr; gap:16px; margin-bottom:16px;">
      <div>
        <label style="<?= $lbl ?>">Position</label>
        <select name="position" style="width:280px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;">
          <?php foreach ($positionLabels as $val => $label): ?>
            <option value="<?= h($val) ?>" <?= ($c['position'] ?? 'laity') === $val ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
      <div>
        <label style="<?= $lbl ?>">Parish</label>
        <input type="text" name="parish" style="<?= $inp ?>" value="<?= $v('parish') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Parish Website <span style="font-weight:400;">(optional)</span></label>
        <input type="url" name="parish_url" style="<?= $inp ?>" value="<?= $v('parish_url') ?>" placeholder="https://…">
      </div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
      <div>
        <label style="<?= $lbl ?>">Diocese</label>
        <input type="text" name="diocese" style="<?= $inp ?>" value="<?= $v('diocese') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Diocese Website <span style="font-weight:400;">(optional)</span></label>
        <input type="url" name="diocese_url" style="<?= $inp ?>" value="<?= $v('diocese_url') ?>" placeholder="https://…">
      </div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
      <div>
        <label style="<?= $lbl ?>">Office / Role Title</label>
        <input type="text" name="office" style="<?= $inp ?>"
               value="<?= $v('office') ?>" placeholder="Vicar General, Pastor Emeritus…">
      </div>
      <div>
        <label style="<?= $lbl ?>">Office Website <span style="font-weight:400;">(optional)</span></label>
        <input type="url" name="office_url" style="<?= $inp ?>" value="<?= $v('office_url') ?>" placeholder="https://…">
      </div>
    </div>
  </div>

  <!-- Contact -->
  <div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Contact Information</h3>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
      <div>
        <label style="<?= $lbl ?>">Address Line 1</label>
        <input type="text" name="address_line1" style="<?= $inp ?>" value="<?= $v('address_line1') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Address Line 2</label>
        <input type="text" name="address_line2" style="<?= $inp ?>" value="<?= $v('address_line2') ?>">
      </div>
    </div>
    <div style="display:grid; grid-template-columns:2fr 1fr 130px 1fr; gap:14px; margin-bottom:14px;">
      <div>
        <label style="<?= $lbl ?>">City</label>
        <input type="text" name="city" style="<?= $inp ?>" value="<?= $v('city') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">State / Province</label>
        <input type="text" name="state_province" style="<?= $inp ?>" value="<?= $v('state_province') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Postal Code</label>
        <input type="text" name="postal_code" style="<?= $inp ?>" value="<?= $v('postal_code') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Country</label>
        <input type="text" name="country" style="<?= $inp ?>" value="<?= $v('country') ?>">
      </div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
      <div>
        <label style="<?= $lbl ?>">Phone</label>
        <input type="tel" name="phone" style="<?= $inp ?>" value="<?= $v('phone') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Email</label>
        <input type="email" name="email" style="<?= $inp ?>" value="<?= $v('email') ?>">
      </div>
    </div>
  </div>

  <!-- Social Media -->
  <div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Social Media</h3>
    <p style="margin:0 0 16px; font-size:13px; color:#888;">Enter full profile URLs (e.g. https://www.facebook.com/yourname)</p>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
      <?php foreach ($socialFields as $key => $label): ?>
      <div>
        <label style="<?= $lbl ?>"><?= h($label) ?></label>
        <input type="url" name="<?= h($key) ?>" style="<?= $inp ?>"
               value="<?= $v($key) ?>" placeholder="https://…">
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Status -->
  <div class="card" style="margin-bottom:28px;">
    <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Status</h3>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
      <div>
        <label style="<?= $lbl ?>">Status</label>
        <select name="status" style="<?= $inp ?>">
          <option value="active"   <?= ($c['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($c['status'] ?? '')        === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div>
        <label style="<?= $lbl ?>">Sort Order <span style="font-weight:400; font-size:12px;">(within position group; lower = first)</span></label>
        <input type="number" name="menu_order" style="width:120px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"
               value="<?= $v('menu_order', '0') ?>" min="0">
      </div>
    </div>
  </div>

  <div style="display:flex; gap:12px;">
    <button type="submit" class="btn btn-primary">Save Clergy Record</button>
    <a href="<?= siteUrl('admin/clergy') ?>" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php });
