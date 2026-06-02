<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requireRole('editor');

$id    = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;

if (!$isNew) {
    $member = Database::fetch(
        "SELECT * FROM board_members WHERE id = ? AND site_id = ?",
        [$id, Database::siteId()]
    );
    if (!$member) { http_response_code(404); die('Board member not found.'); }
} else {
    $member = [
        'id' => 0, 'name' => '', 'title' => '', 'bio' => '',
        'photo_id' => null, 'email' => '',
        'display_order' => 0, 'is_active' => 1,
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $data = [
        'name'          => trim($_POST['name']  ?? ''),
        'title'         => trim($_POST['title'] ?? ''),
        'bio'           => $_POST['bio']        ?? '',
        'email'         => trim($_POST['email'] ?? ''),
        'display_order' => (int)($_POST['display_order'] ?? 0),
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        'photo_id'      => ($_POST['photo_id'] ?? '') !== '' ? (int)$_POST['photo_id'] : null,
        'site_id'       => Database::siteId(),
    ];

    if (!$data['name']) $errors[] = 'Name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }

    if (!$errors) {
        if ($isNew) {
            $newId = Database::insert('board_members', $data);
            PageCache::clearAll();
            flash('success', 'Board member added.');
            redirect(siteUrl('admin/board/' . $newId . '/edit'));
        } else {
            Database::update('board_members', $data, 'id = ? AND site_id = ?', [$id, Database::siteId()]);
            PageCache::clearAll();
            flash('success', 'Board member updated.');
            redirect(siteUrl('admin/board/' . $id . '/edit'));
        }
    }

    $member = array_merge($member, $data, ['id' => $id]);
}

// Photo info
$photo = $member['photo_id']
    ? Database::fetch("SELECT * FROM media WHERE id = ?", [$member['photo_id']])
    : null;

$pageTitle = $isNew ? 'Add Board Member' : 'Edit: ' . $member['name'];
adminLayout($pageTitle, function() use ($member, $isNew, $errors, $photo) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <h1 class="page-title" style="margin:0;"><?= $isNew ? 'Add Board Member' : h('Edit: ' . $member['name']) ?></h1>
  <a href="<?= siteUrl('admin/board') ?>" class="btn btn-secondary">&larr; All Members</a>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="POST" id="board-form">
  <?= csrfField() ?>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:24px; align-items:start;">

    <!-- Left column -->
    <div>
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h2 class="card-title">Profile</h2></div>
        <div class="card-body" style="padding:20px;">

          <div class="form-group">
            <label class="form-label">Full Name <span style="color:red;">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= h($member['name']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Title / Role</label>
            <input type="text" name="title" class="form-control"
                   placeholder="e.g. Board Chair, Treasurer"
                   value="<?= h($member['title'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Email (optional — shown on site)</label>
            <input type="email" name="email" class="form-control" value="<?= h($member['email'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Biography</label>
            <textarea name="bio" id="bio-editor" class="form-control" rows="12"><?= h($member['bio'] ?? '') ?></textarea>
          </div>

        </div>
      </div>
    </div>

    <!-- Right column -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Photo</h2></div>
        <div class="card-body" style="padding:16px;">
          <div id="photo-preview" style="margin-bottom:12px;">
            <?php if ($photo): ?>
            <img src="<?= h(UPLOAD_URL . '/' . $photo['path']) ?>" alt="<?= h($photo['alt_text'] ?: $member['name']) ?>"
                 style="max-width:100%; border-radius:8px; display:block;">
            <?php else: ?>
            <div style="background:#f3f4f6; border:2px dashed #d1d5db; border-radius:8px;
                        padding:32px; text-align:center; color:#9ca3af;">No photo selected</div>
            <?php endif; ?>
          </div>
          <input type="hidden" name="photo_id" id="photo-id" value="<?= (int)$member['photo_id'] ?>">
          <button type="button" class="btn btn-secondary btn-sm" style="width:100%; margin-bottom:6px;"
                  onclick="openMediaPicker()">Choose Photo</button>
          <?php if ($member['photo_id']): ?>
          <button type="button" class="btn btn-sm" style="width:100%; color:#dc2626; background:#fee2e2;"
                  onclick="clearPhoto()">Remove Photo</button>
          <?php endif; ?>
          <p style="font-size:0.8rem; color:#6b7280; margin-top:8px;">
            Recommended: square image, at least 300&times;300 px.
          </p>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Display</h2></div>
        <div class="card-body" style="padding:16px;">
          <div class="form-group">
            <label class="form-label">Display Order</label>
            <input type="number" name="display_order" class="form-control"
                   value="<?= (int)$member['display_order'] ?>" min="0" step="1">
            <small style="color:#6b7280;">Lower numbers appear first.</small>
          </div>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
            <input type="checkbox" name="is_active" value="1"
                   <?= $member['is_active'] ? 'checked' : '' ?>>
            Show on website
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">
        <?= $isNew ? 'Add Member' : 'Save Changes' ?>
      </button>
      <?php if (!$isNew): ?>
      <a href="<?= siteUrl('admin/board') ?>" class="btn btn-secondary"
         style="width:100%; margin-top:8px; text-align:center; display:block;">Cancel</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Media picker modal (reuse the one from page-edit) -->
<div id="media-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
     z-index:1000; overflow:auto; padding:20px;">
  <div style="background:#fff; border-radius:12px; max-width:900px; margin:0 auto; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h2 style="margin:0;">Select Photo</h2>
      <button onclick="closeMediaPicker()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
    </div>
    <div id="media-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px;">
      <?php
      $mediaItems = Database::fetchAll(
          "SELECT * FROM media WHERE site_id = ? AND mime_type LIKE 'image/%' ORDER BY created_at DESC LIMIT 80",
          [Database::siteId()]
      );
      foreach ($mediaItems as $mi):
          $miUrl   = UPLOAD_URL . '/' . $mi['path'];
          $miThumb = UPLOAD_URL . '/' . ($mi['thumb_path'] ?: $mi['path']);
      ?>
      <div class="media-item" data-id="<?= $mi['id'] ?>"
           data-url="<?= h($miUrl) ?>"
           data-alt="<?= h($mi['alt_text'] ?: $mi['original_name']) ?>"
           onclick="selectPhoto(this)"
           style="cursor:pointer; border:2px solid transparent; border-radius:8px; overflow:hidden; aspect-ratio:1;">
        <img src="<?= h($miThumb) ?>"
             alt="<?= h($mi['alt_text'] ?: $mi['original_name']) ?>"
             style="width:100%; height:100%; object-fit:cover;">
      </div>
      <?php endforeach; ?>
      <?php if (empty($mediaItems)): ?>
      <p style="color:#6b7280;">No images in media library. Upload images via the
        <a href="<?= siteUrl('admin/media') ?>">Media</a> page first.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<link rel="stylesheet" href="<?= siteUrl('public/assets/jodit/jodit.min.css') ?>">
<script src="<?= siteUrl('public/assets/jodit/jodit.min.js') ?>"></script>
<script>
function openMediaPicker()  { document.getElementById('media-modal').style.display = 'block'; }
function closeMediaPicker() { document.getElementById('media-modal').style.display = 'none'; }
function clearPhoto() {
  document.getElementById('photo-id').value = '';
  document.getElementById('photo-preview').innerHTML =
    '<div style="background:#f3f4f6; border:2px dashed #d1d5db; border-radius:8px; padding:32px; text-align:center; color:#9ca3af;">No photo selected</div>';
}
function selectPhoto(el) {
  document.getElementById('photo-id').value = el.dataset.id;
  document.getElementById('photo-preview').innerHTML =
    '<img src="' + el.dataset.url + '" alt="' + el.dataset.alt +
    '" style="max-width:100%; border-radius:8px; display:block;">';
  closeMediaPicker();
}
// Jodit is already loaded above — the textarea is in the DOM — initialize directly
if (typeof Jodit !== 'undefined') {
  Jodit.make('#bio-editor', {
    height: 380,
    buttons: 'bold,italic,underline,|,ul,ol,|,link,|,undo,redo',
    showXPathInStatusbar: false,
    showCharsCounter: false,
    showWordsCounter: false,
  });
}
</script>
<?php
});
