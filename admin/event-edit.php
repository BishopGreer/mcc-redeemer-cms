<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once BASE_PATH . '/core/Events.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_events');

$id    = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;

if (!$isNew) {
    $event = Database::fetch("SELECT * FROM events WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
    if (!$event) { http_response_code(404); die('Event not found.'); }
} else {
    $event = [
        'id' => 0, 'title' => '', 'slug' => '', 'description' => '',
        'location' => '', 'address' => '',
        'start_dt' => date('Y-m-d\TH:i', strtotime('+1 day 10:00')),
        'end_dt'   => date('Y-m-d\TH:i', strtotime('+1 day 11:00')),
        'all_day'  => 0, 'status' => 'published',
        'featured_image_id' => null,
        'recur_type' => 'none', 'recur_interval' => 1,
        'recur_days' => '', 'recur_month_type' => 'date',
        'recur_until' => '', 'recur_count' => '',
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $title    = trim($_POST['title']    ?? '');
    $slug     = trim($_POST['slug']     ?? '');
    $desc     = $_POST['description']   ?? '';
    $location = trim($_POST['location'] ?? '');
    $address  = trim($_POST['address']  ?? '');
    $startRaw = $_POST['start_dt']      ?? '';
    $endRaw   = $_POST['end_dt']        ?? '';
    $allDay   = isset($_POST['all_day']) ? 1 : 0;
    $status   = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'published';
    $photoId  = ($_POST['featured_image_id'] ?? '') !== '' ? (int)$_POST['featured_image_id'] : null;

    // Recurrence
    $recurType  = in_array($_POST['recur_type'] ?? 'none', ['none','daily','weekly','monthly','yearly'])
                    ? $_POST['recur_type'] : 'none';
    $recurInt   = max(1, (int)($_POST['recur_interval'] ?? 1));
    $recurDays  = '';
    if ($recurType === 'weekly' && !empty($_POST['recur_days'])) {
        $validDays = array_filter(array_map('intval', (array)$_POST['recur_days']), fn($d) => $d >= 0 && $d <= 6);
        sort($validDays);
        $recurDays = implode(',', $validDays);
    }
    $recurMonthType = ($_POST['recur_month_type'] ?? 'date') === 'day' ? 'day' : 'date';
    $recurEnd       = $_POST['recur_end_type'] ?? 'never';
    $recurUntil     = ($recurEnd === 'date'  && !empty($_POST['recur_until'])) ? $_POST['recur_until'] : null;
    $recurCount     = ($recurEnd === 'count' && !empty($_POST['recur_count']))  ? (int)$_POST['recur_count'] : null;

    if (!$title) $errors[] = 'Title is required.';
    if (!$startRaw) $errors[] = 'Start date/time is required.';

    // Normalize datetimes
    $startDt = $startRaw ? (new DateTimeImmutable($startRaw))->format('Y-m-d H:i:s') : null;
    $endDt   = ($endRaw && !$allDay) ? (new DateTimeImmutable($endRaw))->format('Y-m-d H:i:s') : null;

    if (!$slug && $title) {
        $slug = Events::makeSlug($title, Database::siteId(), $id);
    } else {
        // Sanitise user-supplied slug
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
        $slug = trim($slug, '-') ?: Events::makeSlug($title, Database::siteId(), $id);
    }

    // Slug uniqueness
    $clash = Database::fetch(
        "SELECT id FROM events WHERE slug = ? AND site_id = ? AND id != ?",
        [$slug, Database::siteId(), $id]
    );
    if ($clash) $errors[] = 'That slug is already in use by another event.';

    if (!$errors) {
        $data = [
            'title'             => $title,
            'slug'              => $slug,
            'description'       => $desc,
            'location'          => $location,
            'address'           => $address,
            'start_dt'          => $startDt,
            'end_dt'            => $endDt,
            'all_day'           => $allDay,
            'status'            => $status,
            'featured_image_id' => $photoId,
            'recur_type'        => $recurType,
            'recur_interval'    => $recurInt,
            'recur_days'        => $recurDays ?: null,
            'recur_month_type'  => $recurMonthType,
            'recur_until'       => $recurUntil,
            'recur_count'       => $recurCount,
            'site_id'           => Database::siteId(),
        ];

        if ($isNew) {
            $data['created_by'] = Auth::id();
            $newId = Database::insert('events', $data);
            PageCache::clearAll();
            flash('success', 'Event created.');
            redirect(siteUrl('admin/events/' . $newId . '/edit'));
        } else {
            Database::update('events', $data, 'id = ? AND site_id = ?', [$id, Database::siteId()]);
            PageCache::clearAll();
            flash('success', 'Event saved.');
            redirect(siteUrl('admin/events/' . $id . '/edit'));
        }
    }

    // Re-populate form on error
    $event = array_merge($event, [
        'title' => $title, 'slug' => $slug, 'description' => $desc,
        'location' => $location, 'address' => $address,
        'start_dt' => $startRaw, 'end_dt' => $endRaw, 'all_day' => $allDay,
        'status' => $status, 'featured_image_id' => $photoId,
        'recur_type' => $recurType, 'recur_interval' => $recurInt,
        'recur_days' => $recurDays, 'recur_month_type' => $recurMonthType,
        'recur_until' => $recurUntil ?? '', 'recur_count' => $recurCount ?? '',
    ]);
}

// Format datetimes for <input type="datetime-local">
$fmtDt = fn(?string $v) => $v ? (new DateTimeImmutable($v))->format('Y-m-d\TH:i') : '';
$startVal = $fmtDt($event['start_dt']);
$endVal   = $fmtDt($event['end_dt']);

// Figure out current recur_end_type
$recurEndType = 'never';
if (!empty($event['recur_until'])) $recurEndType = 'date';
elseif (!empty($event['recur_count'])) $recurEndType = 'count';

$photo = $event['featured_image_id']
    ? Database::fetch("SELECT * FROM media WHERE id = ?", [$event['featured_image_id']])
    : null;

$pageTitle = $isNew ? 'Add Event' : 'Edit: ' . $event['title'];
adminLayout($pageTitle, function() use ($event, $isNew, $errors, $photo, $startVal, $endVal, $recurEndType) {
    $DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $recurDays = array_filter(explode(',', $event['recur_days'] ?? ''), fn($v) => $v !== '');
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <h1 class="page-title" style="margin:0;"><?= $isNew ? 'Add Event' : h('Edit: ' . $event['title']) ?></h1>
  <a href="<?= siteUrl('admin/events') ?>" class="btn btn-secondary">&larr; All Events</a>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="POST" id="event-form">
  <?= csrfField() ?>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:24px; align-items:start;">

    <!-- Left: main fields -->
    <div>

      <!-- Core info -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h2 class="card-title">Event Details</h2></div>
        <div class="card-body" style="padding:20px;">

          <div class="form-group">
            <label class="form-label">Title <span style="color:red;">*</span></label>
            <input type="text" name="title" id="event-title" class="form-control"
                   value="<?= h($event['title']) ?>" required
                   oninput="autoSlug(this.value)">
          </div>

          <div class="form-group">
            <label class="form-label">Slug (URL)</label>
            <input type="text" name="slug" id="event-slug" class="form-control"
                   value="<?= h($event['slug']) ?>"
                   placeholder="auto-generated from title">
            <small style="color:#6b7280;">e.g. sunday-worship → /events/sunday-worship</small>
          </div>

          <div class="form-group">
            <label class="form-label">Location / Venue</label>
            <input type="text" name="location" class="form-control"
                   value="<?= h($event['location'] ?? '') ?>"
                   placeholder="e.g. Sanctuary, Fellowship Hall">
          </div>

          <div class="form-group">
            <label class="form-label">Address <span style="color:#9ca3af;">(optional)</span></label>
            <input type="text" name="address" class="form-control"
                   value="<?= h($event['address'] ?? '') ?>"
                   placeholder="e.g. 557 Greene Street, Augusta, GA 30901">
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" id="event-desc" class="form-control" rows="10"><?= h($event['description'] ?? '') ?></textarea>
          </div>

        </div>
      </div>

      <!-- Recurrence -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h2 class="card-title">Recurrence</h2></div>
        <div class="card-body" style="padding:20px;">

          <div class="form-group">
            <label class="form-label">Repeat</label>
            <select name="recur_type" id="recur-type" class="form-control" onchange="showRecurFields()">
              <?php foreach (['none' => 'Does not repeat', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($event['recur_type'] ?? 'none') === $k ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="recur-fields" style="display:none;">

            <div class="form-group">
              <label class="form-label">Repeat every</label>
              <div style="display:flex; align-items:center; gap:10px;">
                <input type="number" name="recur_interval" class="form-control"
                       style="width:80px;" min="1" max="99"
                       value="<?= (int)($event['recur_interval'] ?? 1) ?>">
                <span id="recur-interval-label" style="color:#6b7280;">days</span>
              </div>
            </div>

            <!-- Weekly: day-of-week checkboxes -->
            <div id="recur-days-wrap" class="form-group" style="display:none;">
              <label class="form-label">On days</label>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i => $day): ?>
                <label style="display:flex; flex-direction:column; align-items:center; gap:4px;
                              background:<?= in_array((string)$i, (array)$recurDays) ? '#6B3FA0' : '#f3f4f6' ?>;
                              color:<?= in_array((string)$i, (array)$recurDays) ? '#fff' : '#374151' ?>;
                              border-radius:6px; padding:6px 10px; cursor:pointer;
                              font-size:0.8rem; font-weight:600;"
                      class="dow-label" data-dow="<?= $i ?>">
                  <input type="checkbox" name="recur_days[]" value="<?= $i ?>"
                         <?= in_array((string)$i, (array)$recurDays) ? 'checked' : '' ?>
                         style="display:none;" onchange="updateDowStyle(this)">
                  <?= $day ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Monthly type -->
            <div id="recur-month-wrap" class="form-group" style="display:none;">
              <label class="form-label">Repeat by</label>
              <select name="recur_month_type" class="form-control">
                <option value="date" <?= ($event['recur_month_type'] ?? 'date') === 'date' ? 'selected' : '' ?>>Same day of month (e.g. 15th)</option>
                <option value="day"  <?= ($event['recur_month_type'] ?? 'date') === 'day'  ? 'selected' : '' ?>>Same weekday (e.g. 2nd Tuesday)</option>
              </select>
            </div>

            <!-- End condition -->
            <div class="form-group">
              <label class="form-label">End</label>
              <div style="display:flex; flex-direction:column; gap:8px;">
                <?php foreach (['never' => 'Never', 'date' => 'On date', 'count' => 'After number of occurrences'] as $k => $v): ?>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                  <input type="radio" name="recur_end_type" value="<?= $k ?>"
                         <?= $recurEndType === $k ? 'checked' : '' ?>
                         onchange="showEndFields()">
                  <?= $v ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div id="recur-until-wrap" style="display:none;" class="form-group">
              <label class="form-label">End date</label>
              <input type="date" name="recur_until" class="form-control"
                     value="<?= h($event['recur_until'] ?? '') ?>">
            </div>

            <div id="recur-count-wrap" style="display:none;" class="form-group">
              <label class="form-label">Number of occurrences</label>
              <input type="number" name="recur_count" class="form-control"
                     style="width:100px;" min="1" max="999"
                     value="<?= (int)($event['recur_count'] ?? '') ?: '' ?>">
            </div>

          </div><!-- /recur-fields -->
        </div>
      </div>

    </div><!-- /left column -->

    <!-- Right: date/time, status, image -->
    <div>

      <!-- Date & Time -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Date &amp; Time</h2></div>
        <div class="card-body" style="padding:16px;">

          <div class="form-group">
            <label class="form-label">Start <span style="color:red;">*</span></label>
            <input type="datetime-local" name="start_dt" class="form-control"
                   value="<?= h($startVal) ?>" required>
          </div>

          <div id="end-dt-wrap" class="form-group">
            <label class="form-label">End</label>
            <input type="datetime-local" name="end_dt" class="form-control"
                   value="<?= h($endVal) ?>">
          </div>

          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; margin-top:4px;">
            <input type="checkbox" name="all_day" value="1"
                   <?= $event['all_day'] ? 'checked' : '' ?>
                   onchange="toggleAllDay(this)">
            All-day event
          </label>
        </div>
      </div>

      <!-- Status -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Status</h2></div>
        <div class="card-body" style="padding:16px;">
          <select name="status" class="form-control">
            <option value="published" <?= ($event['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="draft"     <?= ($event['status'] ?? 'published') === 'draft'     ? 'selected' : '' ?>>Draft</option>
          </select>
        </div>
      </div>

      <!-- Featured image -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2 class="card-title">Featured Image</h2></div>
        <div class="card-body" style="padding:16px;">
          <div id="img-preview" style="margin-bottom:10px;">
            <?php if ($photo): ?>
            <img src="<?= h(UPLOAD_URL . '/' . $photo['path']) ?>"
                 style="max-width:100%; border-radius:6px; display:block;">
            <?php else: ?>
            <div style="background:#f3f4f6; border:2px dashed #d1d5db; border-radius:6px;
                        padding:24px; text-align:center; color:#9ca3af; font-size:0.85rem;">No image</div>
            <?php endif; ?>
          </div>
          <input type="hidden" name="featured_image_id" id="img-id" value="<?= (int)$event['featured_image_id'] ?>">
          <button type="button" class="btn btn-secondary btn-sm" style="width:100%; margin-bottom:6px;"
                  onclick="openImgPicker()">Choose Image</button>
          <?php if ($event['featured_image_id']): ?>
          <button type="button" class="btn btn-sm" style="width:100%; color:#dc2626; background:#fee2e2;"
                  onclick="clearImg()">Remove Image</button>
          <?php endif; ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">
        <?= $isNew ? 'Create Event' : 'Save Changes' ?>
      </button>
      <?php if (!$isNew): ?>
      <a href="<?= siteUrl('events/' . $event['slug']) ?>" target="_blank"
         class="btn btn-secondary" style="width:100%; margin-top:8px; text-align:center; display:block;">
        View on Site &nearr;
      </a>
      <?php endif; ?>
    </div>

  </div>
</form>

<!-- Image picker modal -->
<div id="img-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
     z-index:1000; overflow:auto; padding:20px;">
  <div style="background:#fff; border-radius:12px; max-width:900px; margin:0 auto; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h2 style="margin:0;">Select Image</h2>
      <button onclick="closeImgPicker()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px;">
      <?php
      $mediaItems = Database::fetchAll(
          "SELECT * FROM media WHERE site_id = ? AND mime_type LIKE 'image/%' ORDER BY created_at DESC LIMIT 80",
          [Database::siteId()]
      );
      foreach ($mediaItems as $mi):
      ?>
      <div onclick="selectImg(<?= $mi['id'] ?>, '<?= h(UPLOAD_URL . '/' . $mi['path']) ?>')"
           style="cursor:pointer; border:2px solid transparent; border-radius:8px; overflow:hidden; aspect-ratio:1;">
        <img src="<?= h(UPLOAD_URL . '/' . ($mi['thumb_path'] ?: $mi['path'])) ?>"
             alt="<?= h($mi['alt_text'] ?: $mi['original_name']) ?>"
             style="width:100%; height:100%; object-fit:cover;">
      </div>
      <?php endforeach; ?>
      <?php if (empty($mediaItems)): ?>
      <p style="color:#6b7280;">No images uploaded yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<link rel="stylesheet" href="<?= siteUrl('public/assets/jodit/jodit.min.css') ?>">
<script src="<?= siteUrl('public/assets/jodit/jodit.min.js') ?>"></script>
<script>
// ── Jodit ──────────────────────────────────────────────────
if (typeof Jodit !== 'undefined') {
  Jodit.make('#event-desc', {
    height: 340,
    buttons: 'bold,italic,underline,|,ul,ol,|,link,|,undo,redo',
    showXPathInStatusbar: false, showCharsCounter: false, showWordsCounter: false,
  });
}

// ── Slug auto-generation ───────────────────────────────────
let slugEdited = <?= ($isNew || !$event['slug']) ? 'false' : 'true' ?>;
document.getElementById('event-slug').addEventListener('input', () => slugEdited = true);

function autoSlug(val) {
  if (slugEdited) return;
  document.getElementById('event-slug').value =
    val.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

// ── All-day toggle ─────────────────────────────────────────
function toggleAllDay(cb) {
  document.getElementById('end-dt-wrap').style.display = cb.checked ? 'none' : '';
}
if (document.querySelector('[name=all_day]')?.checked) {
  document.getElementById('end-dt-wrap').style.display = 'none';
}

// ── Recurrence fields ──────────────────────────────────────
const intervalLabels = {none:'', daily:'day(s)', weekly:'week(s)', monthly:'month(s)', yearly:'year(s)'};

function showRecurFields() {
  const type = document.getElementById('recur-type').value;
  document.getElementById('recur-fields').style.display     = type === 'none' ? 'none' : '';
  document.getElementById('recur-days-wrap').style.display  = type === 'weekly'  ? '' : 'none';
  document.getElementById('recur-month-wrap').style.display = type === 'monthly' ? '' : 'none';
  document.getElementById('recur-interval-label').textContent = intervalLabels[type] || '';
  showEndFields();
}

function showEndFields() {
  const end = document.querySelector('[name=recur_end_type]:checked')?.value || 'never';
  document.getElementById('recur-until-wrap').style.display = end === 'date'  ? '' : 'none';
  document.getElementById('recur-count-wrap').style.display = end === 'count' ? '' : 'none';
}

// ── Day-of-week button styling ─────────────────────────────
function updateDowStyle(cb) {
  const lbl = cb.closest('label');
  lbl.style.background = cb.checked ? '#6B3FA0' : '#f3f4f6';
  lbl.style.color      = cb.checked ? '#fff'     : '#374151';
}

// ── Image picker ───────────────────────────────────────────
function openImgPicker()  { document.getElementById('img-modal').style.display = 'block'; }
function closeImgPicker() { document.getElementById('img-modal').style.display = 'none'; }
function clearImg() {
  document.getElementById('img-id').value = '';
  document.getElementById('img-preview').innerHTML =
    '<div style="background:#f3f4f6; border:2px dashed #d1d5db; border-radius:6px; padding:24px; text-align:center; color:#9ca3af; font-size:0.85rem;">No image</div>';
}
function selectImg(id, url) {
  document.getElementById('img-id').value = id;
  document.getElementById('img-preview').innerHTML =
    '<img src="' + url + '" style="max-width:100%; border-radius:6px; display:block;">';
  closeImgPicker();
}

// Run on load
showRecurFields();
</script>
<?php
});
