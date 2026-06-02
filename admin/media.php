<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    Media::delete($id);
    flash('success', 'File deleted.');
    redirect(siteUrl('admin/media'));
}

// Handle update alt/caption
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    Auth::verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    Database::update('media', [
        'alt_text' => trim($_POST['alt_text'] ?? ''),
        'caption'  => trim($_POST['caption']  ?? ''),
    ], 'id = ? AND site_id = ?', [$id, Database::siteId()]);
    flash('success', 'Media updated.');
    redirect(siteUrl('admin/media'));
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['q'] ?? '');
$type    = $_GET['type'] ?? '';

$where  = "WHERE site_id = ?";
$params = [Database::siteId()];
if ($search) { $where .= " AND original_name LIKE ?"; $params[] = "%$search%"; }
if ($type === 'image') { $where .= " AND mime_type LIKE 'image/%'"; }
if ($type === 'document') { $where .= " AND mime_type NOT LIKE 'image/%'"; }

$total  = Database::fetch("SELECT COUNT(*) c FROM media $where", $params)['c'];
$items  = Database::fetchAll("SELECT * FROM media $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);

adminLayout('Media Library', function() use ($items, $total, $page, $perPage, $search, $type) {
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
  <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search files…" class="form-control" style="width:200px;">
    <select name="type" class="form-control" style="width:140px;">
      <option value="">All Types</option>
      <option value="image"    <?= $type==='image'    ?'selected':'' ?>>Images</option>
      <option value="document" <?= $type==='document' ?'selected':'' ?>>Documents</option>
    </select>
    <button class="btn btn-secondary">Filter</button>
  </form>
  <label class="btn btn-primary" style="cursor:pointer;">
    &#128247; Upload Files
    <input type="file" id="upload-input" multiple accept="image/*,application/pdf,.doc,.docx,.txt"
           style="display:none;" onchange="uploadFiles(this)">
  </label>
</div>

<div id="upload-progress" style="display:none;" class="alert alert-info">Uploading…</div>

<div class="card">
  <div class="media-grid" id="media-grid">
    <?php if (empty($items)): ?>
      <p style="color:#aaa; font-family:sans-serif;">No files found. Upload some!</p>
    <?php endif; ?>
    <?php foreach ($items as $m): ?>
      <?php $isImg = str_starts_with($m['mime_type'], 'image/'); ?>
      <div class="media-thumb" onclick="openDetail(<?= $m['id'] ?>)">
        <?php if ($isImg && $m['thumb_path']): ?>
          <img src="<?= h(UPLOAD_URL . '/' . $m['thumb_path']) ?>" alt="<?= h($m['alt_text'] ?? '') ?>">
        <?php elseif ($isImg): ?>
          <img src="<?= h(UPLOAD_URL . '/' . $m['path']) ?>" alt="<?= h($m['alt_text'] ?? '') ?>">
        <?php else: ?>
          <div class="media-icon">&#128196;</div>
        <?php endif; ?>
        <div class="media-name"><?= h($m['original_name']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?= pagination($total, $page, $perPage, siteUrl('admin/media') . '?' . http_build_query(['q'=>$search,'type'=>$type])) ?>
</div>

<!-- Detail / edit modal -->
<div id="detail-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:8px; width:700px; max-width:95vw; max-height:90vh; overflow-y:auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e8d9c4;">
      <strong id="dm-name"></strong>
      <button onclick="document.getElementById('detail-modal').style.display='none'"
              style="background:none; border:none; font-size:22px; cursor:pointer;">&times;</button>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0;">
      <div style="background:#f3ece1; display:flex; align-items:center; justify-content:center; padding:20px; min-height:220px;">
        <img id="dm-preview" style="max-width:100%; max-height:280px; border-radius:4px;">
        <div id="dm-file-icon" style="font-size:64px; display:none;">&#128196;</div>
      </div>
      <div style="padding:18px;">
        <form method="post" id="dm-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="dm-id">
          <div class="form-group">
            <label>Alt Text</label>
            <input type="text" name="alt_text" id="dm-alt" class="form-control">
            <div class="form-hint">Describes the image for accessibility.</div>
          </div>
          <div class="form-group">
            <label>Caption</label>
            <textarea name="caption" id="dm-caption" class="form-control" rows="2"></textarea>
          </div>
          <div style="font-size:12px; color:#999; margin-bottom:12px;" id="dm-meta"></div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a id="dm-url-link" href="#" target="_blank" class="btn btn-secondary btn-sm">Open File</a>
            <button type="button" class="btn btn-danger btn-sm" onclick="deleteMedia()">Delete</button>
          </div>
        </form>
        <form method="post" id="dm-delete-form" style="display:none;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" id="dm-del-id">
        </form>
        <div style="margin-top:12px;">
          <label style="font-size:12px; color:#767676; font-family:sans-serif;">File URL (copy to use)</label>
          <input type="text" id="dm-url-field" class="form-control" readonly
                 onclick="this.select()" style="font-size:11px;">
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const mediaData = <?= json_encode(array_map(function($m) {
    return [
        'id'            => $m['id'],
        'original_name' => $m['original_name'],
        'mime_type'     => $m['mime_type'],
        'file_size'     => $m['file_size'],
        'width'         => $m['width'],
        'height'        => $m['height'],
        'alt_text'      => $m['alt_text'] ?? '',
        'caption'       => $m['caption'] ?? '',
        'url'           => UPLOAD_URL . '/' . $m['path'],
        'thumb_url'     => $m['thumb_path'] ? UPLOAD_URL . '/' . $m['thumb_path'] : null,
    ];
}, $items)) ?>;

function openDetail(id) {
  const m = mediaData.find(x => x.id == id);
  if (!m) return;

  document.getElementById('dm-name').textContent    = m.original_name;
  document.getElementById('dm-id').value            = m.id;
  document.getElementById('dm-del-id').value        = m.id;
  document.getElementById('dm-alt').value           = m.alt_text;
  document.getElementById('dm-caption').value       = m.caption;
  document.getElementById('dm-url-field').value     = m.url;
  document.getElementById('dm-url-link').href       = m.url;

  const isImg = m.mime_type && m.mime_type.startsWith('image/');
  document.getElementById('dm-preview').style.display   = isImg ? '' : 'none';
  document.getElementById('dm-file-icon').style.display = isImg ? 'none' : '';
  if (isImg) document.getElementById('dm-preview').src  = m.thumb_url || m.url;

  const size = m.file_size > 1024*1024
    ? (m.file_size/1024/1024).toFixed(1) + ' MB'
    : Math.round(m.file_size/1024) + ' KB';
  const dims = m.width ? ` • ${m.width}×${m.height}px` : '';
  document.getElementById('dm-meta').textContent = `${m.mime_type} • ${size}${dims}`;

  document.getElementById('detail-modal').style.display = 'flex';
}

function deleteMedia() {
  if (!confirm('Permanently delete this file?')) return;
  document.getElementById('dm-delete-form').submit();
}

function uploadFiles(input) {
  const files = Array.from(input.files);
  if (!files.length) return;
  const prog = document.getElementById('upload-progress');
  prog.style.display = 'block';
  prog.textContent   = `Uploading ${files.length} file(s)…`;

  let done = 0;
  files.forEach(file => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('_csrf', '<?= Auth::csrf() ?>');
    fetch('<?= siteUrl('api/media/upload') ?>', {
      method: 'POST', body: fd, credentials: 'same-origin'
    }).then(r => r.json()).then(data => {
      done++;
      if (done === files.length) {
        prog.textContent = 'Upload complete! Refreshing…';
        setTimeout(() => location.reload(), 800);
      }
    }).catch(() => {
      done++;
      prog.textContent = 'One or more uploads failed.';
      prog.className   = 'alert alert-error';
    });
  });
}
</script>

<?php }); ?>
