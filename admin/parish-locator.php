<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_content');

// Table guard
if (!Database::fetch("SHOW TABLES LIKE 'parish_locator'")) {
    adminLayout('Parish Locator', function() { ?>
      <div class="alert alert-error">
        The <strong>parish_locator</strong> table is missing. Please go to
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
        and run pending migrations, then return here.
      </div>
    <?php });
    return;
}

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

// ---------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::delete('parish_locator', 'id = ?', [$delId]);
            flash('success', 'Parish removed from directory.');
        }
        redirect(siteUrl('admin/parish-locator'));
    }

    if ($postAction === 'save') {
        $postId = (int)($_POST['record_id'] ?? 0);

        $lat = trim($_POST['latitude']  ?? '');
        $lng = trim($_POST['longitude'] ?? '');

        $data = [
            'name'           => trim($_POST['name']           ?? ''),
            'pastor_name'    => trim($_POST['pastor_name']    ?? ''),
            'address_line1'  => trim($_POST['address_line1']  ?? ''),
            'address_line2'  => trim($_POST['address_line2']  ?? ''),
            'city'           => trim($_POST['city']           ?? ''),
            'state_province' => trim($_POST['state_province'] ?? ''),
            'postal_code'    => trim($_POST['postal_code']    ?? ''),
            'country'        => trim($_POST['country']        ?? '') ?: 'United States',
            'phone'          => trim($_POST['phone']          ?? ''),
            'email'          => trim($_POST['email']          ?? ''),
            'website'        => trim($_POST['website']        ?? ''),
            'description'    => trim($_POST['description']    ?? ''),
            'latitude'       => is_numeric($lat) ? (float)$lat : null,
            'longitude'      => is_numeric($lng) ? (float)$lng : null,
            'map_embed_url'  => trim($_POST['map_embed_url']  ?? ''),
            'status'         => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
            'menu_order'     => (int)($_POST['menu_order']    ?? 0),
        ];

        if (empty($data['name'])) {
            flash('error', 'Parish name is required.');
            redirect($postId
                ? siteUrl("admin/parish-locator?action=edit&id={$postId}")
                : siteUrl('admin/parish-locator?action=new'));
        }

        if ($postId) {
            Database::update('parish_locator', $data, 'id = ?', [$postId]);
            flash('success', 'Parish updated.');
            redirect(siteUrl("admin/parish-locator?action=edit&id={$postId}"));
        } else {
            Database::insert('parish_locator', $data);
            flash('success', 'Parish added to directory.');
            redirect(siteUrl('admin/parish-locator'));
        }
    }
}

// ---------------------------------------------------------------
// New / Edit form
// ---------------------------------------------------------------
if ($action === 'new' || ($action === 'edit' && $id)) {
    $r = ($action === 'edit' && $id)
        ? Database::fetch("SELECT * FROM parish_locator WHERE id = ?", [$id])
        : null;
    if ($action === 'edit' && !$r) { http_response_code(404); die('Parish not found.'); }

    $postUrl = siteUrl($id ? "admin/parish-locator?action=edit&id={$id}" : 'admin/parish-locator?action=new');

    adminLayout($r ? 'Edit Parish' : 'Add Parish', function() use ($r, $id, $postUrl) {
    ?>
    <div style="margin-bottom:16px;">
      <a href="<?= siteUrl('admin/parish-locator') ?>" class="btn btn-secondary btn-sm">&larr; All Parishes</a>
    </div>

    <form method="post" action="<?= h($postUrl) ?>" style="max-width:860px;">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="record_id" value="<?= $id ?>">

      <!-- Parish Info -->
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Parish Information</h3>
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:14px;">
          <label style="display:block;">Parish Name *<br>
            <input type="text" name="name" value="<?= h($r['name'] ?? '') ?>" required
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">Status<br>
            <select name="status" style="width:100%; margin-top:4px; padding:8px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;">
              <option value="active"   <?= ($r['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= ($r['status'] ?? '')        === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select></label>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <label style="display:block;">Pastor / Priest in Charge<br>
            <input type="text" name="pastor_name" value="<?= h($r['pastor_name'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">Display Order <small style="color:#888;">(lower = first)</small><br>
            <input type="number" name="menu_order" value="<?= (int)($r['menu_order'] ?? 0) ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        </div>
        <label style="display:block;">Brief Description<br>
          <textarea name="description" rows="3"
                    style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"><?= h($r['description'] ?? '') ?></textarea></label>
      </div>

      <!-- Address -->
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Address</h3>
        <label style="display:block; margin-bottom:12px;">Address Line 1<br>
          <input type="text" name="address_line1" value="<?= h($r['address_line1'] ?? '') ?>"
                 style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        <label style="display:block; margin-bottom:12px;">Address Line 2<br>
          <input type="text" name="address_line2" value="<?= h($r['address_line2'] ?? '') ?>"
                 style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px; margin-bottom:12px;">
          <label style="display:block;">City<br>
            <input type="text" name="city" value="<?= h($r['city'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">State / Province<br>
            <input type="text" name="state_province" value="<?= h($r['state_province'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">Postal Code<br>
            <input type="text" name="postal_code" value="<?= h($r['postal_code'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        </div>
        <label style="display:block;">Country<br>
          <input type="text" name="country" value="<?= h($r['country'] ?? 'United States') ?>"
                 style="width:360px; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
      </div>

      <!-- Contact -->
      <div class="card" style="margin-bottom:20px;">
        <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Contact</h3>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <label style="display:block;">Phone<br>
            <input type="tel" name="phone" value="<?= h($r['phone'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">Email<br>
            <input type="email" name="email" value="<?= h($r['email'] ?? '') ?>"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        </div>
        <label style="display:block;">Website<br>
          <input type="url" name="website" value="<?= h($r['website'] ?? '') ?>" placeholder="https://"
                 style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
      </div>

      <!-- Map -->
      <div class="card" style="margin-bottom:24px;">
        <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; color:#1b2d42;">Map</h3>
        <p style="font-size:13px; color:#666; margin:0 0 16px;">
          Enter coordinates to auto-generate an OpenStreetMap embed, or paste a custom embed URL
          (the <code>src</code> value only, not a full <code>&lt;iframe&gt;</code> tag).
          The custom URL takes priority when both are provided.
        </p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <label style="display:block;">Latitude<br>
            <input type="text" name="latitude" value="<?= h($r['latitude'] ?? '') ?>" placeholder="e.g. 41.8781"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
          <label style="display:block;">Longitude<br>
            <input type="text" name="longitude" value="<?= h($r['longitude'] ?? '') ?>" placeholder="e.g. -87.6298"
                   style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
        </div>
        <label style="display:block;">Custom Map Embed URL <small style="color:#888;">(overrides coordinates if set)</small><br>
          <input type="text" name="map_embed_url" value="<?= h($r['map_embed_url'] ?? '') ?>"
                 placeholder="https://www.google.com/maps/embed?pb=..."
                 style="width:100%; margin-top:4px; padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:15px;"></label>
      </div>

      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $id ? 'Update Parish' : 'Add Parish' ?></button>
        <a href="<?= siteUrl('admin/parish-locator') ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
    <?php
    });
    return;
}

// ---------------------------------------------------------------
// List
// ---------------------------------------------------------------
$parishes = Database::fetchAll(
    "SELECT * FROM parish_locator ORDER BY menu_order ASC, country ASC, name ASC"
);

adminLayout('Parish Locator', function() use ($parishes) {
?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <p style="margin:0; color:#666; font-size:13px;"><?= count($parishes) ?> parish(es) in directory</p>
  <div style="display:flex; gap:8px;">
    <a href="<?= siteUrl('find-a-parish') ?>" target="_blank" class="btn btn-secondary btn-sm">&#127760; View Public Page</a>
    <a href="<?= siteUrl('admin/parish-locator?action=new') ?>" class="btn btn-primary">+ Add Parish</a>
  </div>
</div>

<?php if (empty($parishes)): ?>
  <div class="card" style="text-align:center; padding:48px; color:#aaa;">
    No parishes added yet. <a href="<?= siteUrl('admin/parish-locator?action=new') ?>">Add the first one.</a>
  </div>
<?php else: ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Parish</th>
          <th>Pastor</th>
          <th>Location</th>
          <th>Contact</th>
          <th>Map</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parishes as $p): ?>
        <tr style="<?= $p['status'] === 'inactive' ? 'opacity:.5;' : '' ?>">
          <td>
            <strong><?= h($p['name']) ?></strong>
            <?php if ($p['description']): ?>
              <div style="font-size:12px; color:#888; margin-top:2px;">
                <?= h(mb_substr(strip_tags($p['description']), 0, 60)) ?>...
              </div>
            <?php endif; ?>
          </td>
          <td style="font-size:13px;"><?= h($p['pastor_name'] ?: '—') ?></td>
          <td style="font-size:13px;">
            <?= h(implode(', ', array_filter([$p['city'], $p['state_province'], $p['country']]))) ?>
          </td>
          <td style="font-size:12px; line-height:1.7;">
            <?php if ($p['phone']): ?><div>&#128222; <?= h($p['phone']) ?></div><?php endif; ?>
            <?php if ($p['email']): ?><div>&#9993; <?= h($p['email']) ?></div><?php endif; ?>
            <?php if ($p['website']): ?>
              <div>&#127760; <a href="<?= h($p['website']) ?>" target="_blank">Website</a></div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px; color:#888;">
            <?php if ($p['map_embed_url']): ?>
              <span title="Custom embed URL">&#128205; Custom</span>
            <?php elseif ($p['latitude'] && $p['longitude']): ?>
              <span title="Auto-generated from coordinates">&#128205; Coords</span>
            <?php else: ?>
              <span style="color:#ccc;">None</span>
            <?php endif; ?>
          </td>
          <td>
            <span style="font-size:12px; padding:2px 8px; border-radius:10px;
                         background:<?= $p['status']==='active' ? '#d4edda' : '#f8d7da' ?>;
                         color:<?= $p['status']==='active' ? '#155724' : '#721c24' ?>;">
              <?= ucfirst($p['status']) ?>
            </span>
          </td>
          <td style="white-space:nowrap;">
            <a href="<?= siteUrl('admin/parish-locator?action=edit&id=' . $p['id']) ?>"
               class="btn btn-sm btn-secondary">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Remove <?= h(addslashes($p['name'])) ?> from the directory?')">
              <?= csrfField() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php });
