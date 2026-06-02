<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Lectionary.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_content');

if (Database::siteId() !== 1) {
    http_response_code(403);
    echo '<h1>Access denied.</h1><p>Daily Readings are only available on the main site.</p>';
    exit;
}

$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $postAction = $_POST['action'] ?? '';

    // Save translation + auto-fetch settings
    if ($postAction === 'save_settings') {
        $sid         = Database::siteId();
        $translation = trim($_POST['readings_translation'] ?? 'drb');
        $autoFetch   = isset($_POST['readings_auto_fetch']) ? '1' : '0';
        Database::query(
            "INSERT INTO settings (site_id, `key`, value) VALUES (?, 'readings_translation', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$sid, $translation]
        );
        Database::query(
            "INSERT INTO settings (site_id, `key`, value) VALUES (?, 'readings_auto_fetch', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$sid, $autoFetch]
        );
        flash('success', 'Settings saved.');
        redirect(siteUrl('admin/daily-readings'));
    }

    // Manual prefetch for next N days
    if ($postAction === 'prefetch') {
        $days   = min(60, max(1, (int)($_POST['prefetch_days'] ?? 30)));
        $start  = new DateTimeImmutable('today');
        $result = Lectionary::autoFetchRange($start, $days);
        flash('success', "Prefetch complete: {$result['fetched']} days fetched, {$result['failed']} failed.");
        redirect(siteUrl('admin/daily-readings'));
    }

    // Force-refresh today's readings (ignores any cached/existing text)
    if ($postAction === 'test_fetch') {
        $today   = new DateTimeImmutable('today');
        // $force = true so existing text is always replaced with fresh data
        $success = Lectionary::autoFetchForDate($today, true);
        if ($success) {
            $row     = Lectionary::readingsForDate($today, true);
            $hasText = !empty($row['gospel_text']);
            if ($hasText) {
                flash('success', 'Refresh complete. Today\'s readings and passage text were updated. Reload the public page to see the result.');
            } else {
                flash('info', 'USCCB connection works (references updated) but passage text could not be parsed. Readings will show references only.');
            }
        } else {
            flash('error', 'Refresh FAILED — could not reach bible.usccb.org. Check that your server allows outbound HTTPS requests.');
        }
        redirect(siteUrl('admin/daily-readings'));
    }

    // Manual fetch for a single date
    if ($postAction === 'fetch_date') {
        $dateStr = trim($_POST['fetch_date'] ?? '');
        if ($dateStr) {
            $d = new DateTimeImmutable($dateStr);
            if (Lectionary::autoFetchForDate($d)) {
                flash('success', 'Readings fetched for ' . $d->format('F j, Y') . '.');
            } else {
                flash('error', 'Could not fetch readings for ' . $d->format('F j, Y') . '. USCCB may not have that date yet.');
            }
        }
        redirect(siteUrl('admin/daily-readings'));
    }

    // Save (add or update) a reading entry
    if ($postAction === 'save_reading') {
        $rid = (int)($_POST['id'] ?? 0);

        $lk = trim($_POST['lookup_key'] ?? '');
        if (!$lk) {
            flash('error', 'Lookup key is required.');
            redirect(siteUrl('admin/daily-readings?action=' . ($rid ? "edit&id={$rid}" : 'new')));
        }

        $data = [
            'lookup_key'       => $lk,
            'date_override'    => trim($_POST['date_override'] ?? '') ?: null,
            'liturgical_title' => trim($_POST['liturgical_title'] ?? ''),
            'reading1_ref'     => trim($_POST['reading1_ref'] ?? '') ?: null,
            'reading1_api'     => null,   // no longer used
            'reading1_text'    => trim($_POST['reading1_text'] ?? '') ?: null,
            'psalm_ref'        => trim($_POST['psalm_ref'] ?? '') ?: null,
            'psalm_api'        => null,
            'psalm_text'       => trim($_POST['psalm_text'] ?? '') ?: null,
            'reading2_ref'     => trim($_POST['reading2_ref'] ?? '') ?: null,
            'reading2_api'     => null,
            'reading2_text'    => trim($_POST['reading2_text'] ?? '') ?: null,
            'gospel_ref'       => trim($_POST['gospel_ref'] ?? '') ?: null,
            'gospel_api'       => null,
            'gospel_text'      => trim($_POST['gospel_text'] ?? '') ?: null,
            'notes'            => trim($_POST['notes'] ?? '') ?: null,
        ];

        if ($rid) {
            Database::update('lectionary_readings', $data, 'id = ?', [$rid]);
            flash('success', 'Reading updated.');
        } else {
            Database::insert('lectionary_readings', $data);
            flash('success', 'Reading added.');
        }
        redirect(siteUrl('admin/daily-readings'));
    }

    // Delete
    if ($postAction === 'delete') {
        Database::delete('lectionary_readings', 'id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('success', 'Reading deleted.');
        redirect(siteUrl('admin/daily-readings'));
    }

    // Clear text cache
    if ($postAction === 'clear_cache') {
        Database::query("TRUNCATE TABLE readings_cache");
        flash('success', 'Bible text cache cleared.');
        redirect(siteUrl('admin/daily-readings'));
    }

    // Import CPDV JSON file into local cpdv_verses table
    if ($postAction === 'import_cpdv') {
        $filePath = trim($_POST['cpdv_path'] ?? '') ?: (BASE_PATH . '/cpdv.json');
        $result   = Lectionary::importCPDV($filePath);
        if (isset($result['error'])) {
            flash('error', 'CPDV import failed: ' . $result['error']);
        } else {
            flash('success',
                'CPDV imported: ' . number_format($result['imported']) . ' verses across 73 books. ' .
                'Click "Test &amp; Refresh Today" to re-fetch today\'s readings with CPDV text.');
        }
        redirect(siteUrl('admin/daily-readings'));
    }

    // Apply CPDV text to all existing lectionary entries that have references
    if ($postAction === 'apply_cpdv_all') {
        $entries = Database::fetchAll(
            "SELECT id, reading1_ref, psalm_ref, reading2_ref, gospel_ref FROM lectionary_readings"
        );
        $updated = 0;
        foreach ($entries as $entry) {
            $upd = [];
            foreach (['reading1', 'psalm', 'reading2', 'gospel'] as $slot) {
                $ref = $entry[$slot . '_ref'] ?? null;
                if ($ref) {
                    $t = Lectionary::lookupCPDV($ref);
                    if ($t) $upd[$slot . '_text'] = $t;
                }
            }
            if ($upd) {
                Database::update('lectionary_readings', $upd, 'id = ?', [$entry['id']]);
                $updated++;
            }
        }
        flash('success', "CPDV text applied to {$updated} existing entries.");
        redirect(siteUrl('admin/daily-readings'));
    }
}

// ── Data for render ──────────────────────────────────────────────────────────
$today     = new DateTimeImmutable('today');
$todayInfo = Lectionary::liturgicalDay($today);
$todayRow  = Lectionary::readingsForDate($today, true);

$editing = null;
if ($action === 'edit' && $editId) {
    $editing = Database::fetch("SELECT * FROM lectionary_readings WHERE id = ?", [$editId]);
}

$listPage = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;
$offset   = ($listPage - 1) * $perPage;
$total    = Database::fetch("SELECT COUNT(*) c FROM lectionary_readings")['c'];
$rows     = Database::fetchAll(
    "SELECT * FROM lectionary_readings ORDER BY date_override DESC, lookup_key ASC LIMIT {$perPage} OFFSET {$offset}"
);

$cacheCount  = Database::fetch("SELECT COUNT(*) c FROM readings_cache")['c'] ?? 0;
try {
    $cpdvCount = Database::fetch("SELECT COUNT(*) c FROM cpdv_verses")['c'] ?? 0;
} catch (\Throwable $e) {
    $cpdvCount = -1; // table not yet created (migration 0028 pending)
}
$translation = setting('readings_translation', 'drb');

$translationNames = [
    'drb'  => 'Douay-Rheims Bible (DRB) — Traditional Catholic',
    'kjv'  => 'King James Version (KJV)',
    'web'  => 'World English Bible (WEB)',
    'asv'  => 'American Standard Version (ASV)',
    'bbe'  => 'Bible in Basic English (BBE)',
];
$autoFetch = setting('readings_auto_fetch', '1');

adminLayout('Daily Readings', function() use (
    $todayInfo, $todayRow, $rows, $total, $listPage, $perPage,
    $action, $editing, $editId, $cacheCount, $cpdvCount, $translation, $translationNames, $autoFetch
) {
?>

<!-- ── Today's Liturgical Day ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div style="padding:14px 20px; border-bottom:1px solid #eee;">
    <h3 style="margin:0; font-size:15px; color:var(--brown);">
      Today &mdash; <?= h(date('l, F j, Y')) ?>
    </h3>
  </div>
  <div style="padding:14px 20px; display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px;">
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Liturgical Day</label>
      <strong><?= h($todayInfo['title']) ?></strong>
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Season</label>
      <?= h(ucfirst($todayInfo['season'])) ?>
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Sunday Cycle</label>
      Year <?= h($todayInfo['sunday_cycle']) ?>
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Weekday Cycle</label>
      Cycle <?= h($todayInfo['weekday_cycle']) ?>
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Primary Lookup Key</label>
      <code style="font-size:12px; background:#f5f5f5; padding:2px 5px; border-radius:3px;"><?= h($todayInfo['lookup_key']) ?></code>
    </div>
    <?php if ($todayInfo['lookup_key_w'] !== $todayInfo['lookup_key']): ?>
    <div>
      <label style="font-size:11px;text-transform:uppercase;color:#888;display:block;">Weekday Key</label>
      <code style="font-size:12px; background:#f5f5f5; padding:2px 5px; border-radius:3px;"><?= h($todayInfo['lookup_key_w']) ?></code>
    </div>
    <?php endif; ?>
  </div>
  <div style="padding:6px 20px 14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <?php if ($todayRow): ?>
      <span style="color:#2e7d32; font-size:13px;">&#10003; Readings configured:
        <strong><?= h($todayRow['liturgical_title']) ?></strong></span>
      <a href="<?= siteUrl('admin/daily-readings?action=edit&id=' . $todayRow['id']) ?>"
         class="btn btn-sm btn-secondary">Edit Today's Entry</a>
    <?php else: ?>
      <span style="color:#c62828; font-size:13px;">&#9888; No readings entered for today.</span>
      <a href="<?= siteUrl('admin/daily-readings?action=new&prefill_key=' . urlencode($todayInfo['lookup_key']) . '&prefill_title=' . urlencode($todayInfo['title'])) ?>"
         class="btn btn-sm btn-primary">+ Add Today's Readings</a>
    <?php endif; ?>
    <a href="<?= siteUrl('daily-readings') ?>" target="_blank" class="btn btn-sm btn-secondary">View Public Page</a>
  </div>
</div>

<?php if ($action === 'new' || $action === 'edit'): ?>

<!-- ── Add / Edit Form ────────────────────────────────────────────────── -->
<?php
$e = $editing ?? [
    'id'               => 0,
    'lookup_key'       => $_GET['prefill_key']   ?? '',
    'date_override'    => '',
    'liturgical_title' => $_GET['prefill_title'] ?? '',
    'reading1_ref' => '', 'reading1_text' => '',
    'psalm_ref'    => '', 'psalm_text'    => '',
    'reading2_ref' => '', 'reading2_text' => '',
    'gospel_ref'   => '', 'gospel_text'   => '',
    'notes'        => '',
];
$formTitle = $e['id'] ? 'Edit Reading Entry' : 'Add Reading Entry';
?>
<div class="card" style="margin-bottom:20px;">
  <div style="padding:14px 20px; border-bottom:1px solid #eee;
              display:flex; justify-content:space-between; align-items:center;">
    <h3 style="margin:0; font-size:15px;"><?= $formTitle ?></h3>
    <a href="<?= siteUrl('admin/daily-readings') ?>" class="btn btn-sm btn-secondary">Cancel</a>
  </div>

  <form method="post" style="padding:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_reading">
    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">

    <!-- Lookup Key + Date -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
      <div>
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
          Lookup Key <span style="color:#c00;">*</span>
        </label>
        <input type="text" name="lookup_key" value="<?= h($e['lookup_key']) ?>" required
               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;
                      font-family:monospace; font-size:13px;"
               placeholder="e.g. S-ordinary-3-A or DATE-2026-12-25">
        <p style="margin:4px 0 0; font-size:11px; color:#888;">
          Sundays: <code>S-{season}-{week}-{A|B|C}</code><br>
          Weekdays: <code>W-{season}-{week}-{0-6}-{I|II}</code><br>
          Specific date: <code>DATE-YYYY-MM-DD</code>
        </p>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
          Date Override <span style="font-weight:400; color:#aaa;">(optional)</span>
        </label>
        <input type="date" name="date_override" value="<?= h($e['date_override'] ?? '') ?>"
               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; font-size:13px;">
        <p style="margin:4px 0 0; font-size:11px; color:#888;">
          Set to match this entry to an exact date regardless of the lookup key.
        </p>
      </div>
    </div>

    <!-- Title -->
    <div style="margin-bottom:16px;">
      <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
        Liturgical Title
      </label>
      <input type="text" name="liturgical_title" value="<?= h($e['liturgical_title']) ?>"
             style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; font-size:13px;"
             placeholder="e.g. Third Sunday of Ordinary Time, Year A">
    </div>

    <!-- How text is sourced -->
    <div style="background:#e8f5e9; border:1px solid #a5d6a7; border-radius:5px;
                padding:10px 14px; margin-bottom:18px; font-size:13px; color:#2e7d32;">
      <strong>How text works:</strong>
      Paste text directly in the <em>Passage Text</em> box (highest priority).
      Or just fill in the <em>Reference</em> and leave text blank — the site will
      automatically fetch it from the <strong>Douay-Rheims Bible</strong> (free, no key needed).
    </div>

    <?php
    $readingFields = [
        ['label' => 'First Reading',      'key' => 'reading1', 'note' => ''],
        ['label' => 'Responsorial Psalm', 'key' => 'psalm',    'note' => ''],
        ['label' => 'Second Reading',     'key' => 'reading2', 'note' => 'Leave blank on weekdays'],
        ['label' => 'Gospel',             'key' => 'gospel',   'note' => ''],
    ];
    foreach ($readingFields as $rf):
        $refKey  = $rf['key'] . '_ref';
        $textKey = $rf['key'] . '_text';
    ?>
    <div style="background:#fafafa; border:1px solid #eee; border-radius:6px;
                padding:14px; margin-bottom:14px;">
      <div style="font-size:13px; font-weight:600; color:#555; margin-bottom:10px;">
        <?= h($rf['label']) ?>
        <?php if ($rf['note']): ?>
          <span style="font-weight:400; font-size:11px; color:#aaa;">&mdash; <?= h($rf['note']) ?></span>
        <?php endif; ?>
      </div>
      <div style="margin-bottom:8px;">
        <label style="display:block; font-size:11px; color:#888; margin-bottom:3px;">
          Reference (e.g. Is 42:1-7 or Jn 3:16-21)
        </label>
        <input type="text" name="<?= $refKey ?>" value="<?= h($e[$refKey] ?? '') ?>"
               style="width:100%; padding:7px; border:1px solid #ddd; border-radius:4px; font-size:13px;"
               placeholder="Leave blank to omit this reading">
      </div>
      <div>
        <label style="display:block; font-size:11px; color:#888; margin-bottom:3px;">
          Passage Text <span style="font-style:italic;">(paste here to override auto-fetch)</span>
        </label>
        <textarea name="<?= $textKey ?>" rows="4"
                  style="width:100%; padding:7px; border:1px solid #ddd; border-radius:4px;
                         font-size:13px; line-height:1.5; resize:vertical;"
                  placeholder="Optional — paste the passage text here. If left blank, text will be fetched automatically using the reference above."><?= h($e[$textKey] ?? '') ?></textarea>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Notes -->
    <div style="margin-bottom:20px;">
      <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
        Notes / Antiphon <span style="font-weight:400; color:#aaa;">(optional)</span>
      </label>
      <textarea name="notes" rows="2"
                style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;
                       font-size:13px; resize:vertical;"
                placeholder="Optional commentary, antiphon, or pastoral note displayed above the readings"><?= h($e['notes'] ?? '') ?></textarea>
    </div>

    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn btn-primary">Save Reading</button>
      <a href="<?= siteUrl('admin/daily-readings') ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php else: ?>

<!-- ── Settings ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div style="padding:14px 20px; border-bottom:1px solid #eee;">
    <h3 style="margin:0; font-size:15px;">Settings</h3>
  </div>
  <form method="post" style="padding:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_settings">

    <!-- Auto-fetch toggle -->
    <div style="margin-bottom:18px; padding:14px 16px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:6px;">
      <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:600;">
        <input type="checkbox" name="readings_auto_fetch" value="1"
               <?= $autoFetch === '1' ? 'checked' : '' ?>
               style="width:16px; height:16px;">
        Auto-fetch daily readings from USCCB
      </label>
      <p style="margin:6px 0 0 26px; font-size:12px; color:#2e7d32;">
        When enabled, readings are automatically retrieved from
        <a href="https://bible.usccb.org" target="_blank" rel="noopener">bible.usccb.org</a>
        whenever a day has no entry in the database. The liturgical citation
        (e.g. "Isaiah 42:1-7") is captured; passage text is then fetched from the
        Douay-Rheims Bible. <strong>Recommended: leave this on.</strong>
      </p>
    </div>

    <p style="font-size:12px; color:#666; margin-bottom:14px; max-width:560px;">
      Reading references (book and chapter) are pulled from <strong>bible.usccb.org</strong>.
      If the <strong>CPDV local Bible</strong> is imported (see card below), passage text
      is served from your own database. Otherwise text is extracted from the USCCB page itself
      (NABRE). No external API key is ever required.
    </p>
    <input type="hidden" name="readings_translation" value="<?= h($translation) ?>">

    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <?php if ($cacheCount > 0): ?>
        <form method="post" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="clear_cache">
          <button type="submit" class="btn btn-sm btn-secondary"
                  onclick="return confirm('Clear the Bible text cache?')">
            Clear Text Cache (<?= (int)$cacheCount ?> entries)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ── CPDV Local Bible ───────────────────────────────────────────────── -->
<?php if ($cpdvCount >= 0): ?>
<div class="card" style="margin-bottom:20px;">
  <div style="padding:14px 20px; border-bottom:1px solid #eee;
              display:flex; justify-content:space-between; align-items:center;">
    <h3 style="margin:0; font-size:15px;">CPDV Local Bible Text</h3>
    <?php if ($cpdvCount > 0): ?>
      <span style="font-size:12px; color:#2e7d32;">
        &#10003; <?= number_format($cpdvCount) ?> verses loaded
      </span>
    <?php endif; ?>
  </div>
  <div style="padding:16px 20px;">

    <?php if ($cpdvCount > 0): ?>
    <p style="margin:0 0 14px; font-size:13px; color:#333;">
      The <strong>Catholic Public Domain Version</strong> (<?= number_format($cpdvCount) ?> verses,
      all 73 Catholic canon books) is installed locally. Reading text is served from your
      own database — no external Bible API needed.
    </p>
    <?php else: ?>
    <p style="margin:0 0 14px; font-size:13px; color:#555; max-width:580px;">
      The <strong>Catholic Public Domain Version</strong> (CPDV) is a modern English
      translation of the Latin Vulgate covering all 73 Catholic canon books, including
      Sirach, Tobit, Maccabees, Wisdom, Baruch, and Judith. Once imported, readings
      text is served directly from your database with no external API calls.
    </p>
    <div style="background:#fff8e1; border:1px solid #ffe082; border-radius:4px;
                padding:10px 14px; margin-bottom:14px; font-size:12px; color:#5d4037;">
      <strong>Setup:</strong> Upload <code>cpdv.json</code> to your server via FileZilla,
      then enter the full server path below and click <em>Import</em>.
    </div>
    <?php endif; ?>

    <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import_cpdv">
      <input type="text" name="cpdv_path"
             value="<?= h(BASE_PATH . '/cpdv.json') ?>"
             placeholder="/full/server/path/to/cpdv.json"
             style="flex:1; min-width:280px; padding:7px 10px; border:1px solid #ddd;
                    border-radius:4px; font-size:13px; font-family:monospace;">
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('<?= $cpdvCount > 0 ? 'This will replace the existing CPDV data. Proceed?' : 'Import CPDV Bible text? This may take a moment.' ?>')">
        <?= $cpdvCount > 0 ? 'Re-import CPDV' : 'Import CPDV' ?>
      </button>
    </form>

    <?php if ($cpdvCount > 0): ?>
    <form method="post" style="display:inline;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="apply_cpdv_all">
      <button type="submit" class="btn btn-sm btn-secondary"
              onclick="return confirm('Apply CPDV text to all existing reading entries? This will update entries that currently use scraped USCCB text.')">
        Apply CPDV to All Existing Entries
      </button>
    </form>
    <p style="margin:8px 0 0; font-size:11px; color:#888;">
      Use this after importing CPDV to update readings already in the database.
      Manually pasted text is replaced; re-paste if needed.
    </p>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- ── Prefetch Panel ─────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div style="padding:14px 20px; border-bottom:1px solid #eee;">
    <h3 style="margin:0; font-size:15px;">Prefetch Readings</h3>
  </div>
  <div style="padding:16px 20px;">
    <p style="margin:0 0 14px; font-size:13px; color:#555;">
      Use these tools to populate the readings database in bulk.
      Auto-fetch pulls liturgical citations from the USCCB website for each day.
      Passage text is fetched on demand when pages are viewed.
    </p>
    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">

      <!-- Bulk prefetch -->
      <form method="post" style="display:flex; gap:8px; align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="prefetch">
        <select name="prefetch_days"
                style="padding:7px 10px; border:1px solid #ddd; border-radius:4px; font-size:13px;">
          <option value="7">Next 7 days</option>
          <option value="30" selected>Next 30 days</option>
          <option value="60">Next 60 days</option>
        </select>
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('This will fetch readings from USCCB for each day in the selected range. It may take up to a minute. Proceed?')">
          Prefetch from USCCB
        </button>
      </form>

      <!-- Single date fetch -->
      <form method="post" style="display:flex; gap:8px; align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="fetch_date">
        <input type="date" name="fetch_date" value="<?= h(date('Y-m-d')) ?>"
               style="padding:7px; border:1px solid #ddd; border-radius:4px; font-size:13px;">
        <button type="submit" class="btn btn-secondary">Fetch Single Date</button>
      </form>

    </div>
    <p style="margin:12px 0 0; font-size:12px; color:#888;">
      Days already in the database are skipped. Auto-fetch also runs automatically when
      visitors view the public readings page for a day not yet in the database.
    </p>

    <!-- Connection test -->
    <div style="margin-top:16px; padding-top:14px; border-top:1px solid #eee;">
      <strong style="font-size:13px;">Test the connection</strong>
      <span style="font-size:13px; color:#555;"> &mdash; fetches today's readings from USCCB and stores them (including passage text):</span>
      <form method="post" style="display:inline; margin-left:10px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="test_fetch">
        <button type="submit" class="btn btn-sm btn-secondary">Test &amp; Refresh Today</button>
      </form>
    </div>
  </div>
</div>

<!-- ── Reading Entries Table ─────────────────────────────────────────── -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
  <h3 style="margin:0; font-size:15px;">Reading Entries (<?= (int)$total ?>)</h3>
  <a href="<?= siteUrl('admin/daily-readings?action=new') ?>" class="btn btn-primary">+ Add Entry</a>
</div>

<?php if ($total === 0): ?>
<div style="background:#fff8e1; border:1px solid #ffe082; border-radius:6px;
            padding:20px 24px; margin-bottom:16px; color:#5d4037;">
  <strong>No readings entered yet.</strong> Click <em>+ Add Entry</em> above to add your first reading.
  Each entry covers one liturgical day (a Sunday, weekday, or feast).
  You can add entries for individual dates using the <em>Date Override</em> field,
  or for recurring liturgical days using the lookup key format shown in the Today card.
</div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Lookup Key</th>
          <th>Date Override</th>
          <th>Title</th>
          <th>Has Text?</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><code style="font-size:12px;"><?= h($r['lookup_key']) ?></code></td>
          <td style="font-size:12px; color:#888;">
            <?= $r['date_override'] ? h($r['date_override']) : '<span style="color:#ddd;">—</span>' ?>
          </td>
          <td><?= h($r['liturgical_title']) ?></td>
          <td style="font-size:12px;">
            <?php
            $hasText = ($r['reading1_text'] || $r['psalm_text'] || $r['reading2_text'] || $r['gospel_text']);
            $hasRef  = ($r['reading1_ref'] || $r['psalm_ref'] || $r['gospel_ref']);
            if ($hasText)       echo '<span style="color:#2e7d32;">&#10003; Pasted</span>';
            elseif ($hasRef)    echo '<span style="color:#1565c0;">&#8635; Auto-fetch</span>';
            else                echo '<span style="color:#ccc;">None</span>';
            ?>
          </td>
          <td>
            <div class="actions">
              <a href="<?= siteUrl('admin/daily-readings?action=edit&id=' . $r['id']) ?>"
                 class="btn btn-sm btn-secondary">Edit</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this reading entry?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $listPage, $perPage, siteUrl('admin/daily-readings')) ?>
</div>
<?php endif; ?>

<div style="margin-top:16px; padding:12px 16px; background:#f5f5f5; border-radius:6px; font-size:13px; color:#555;">
  <strong>Shortcode:</strong> Add <code>[daily-readings]</code> to any page body to embed today's readings inline.
  Dedicated public page: <a href="<?= siteUrl('daily-readings') ?>" target="_blank">/daily-readings</a>
</div>

<?php endif; ?>

<?php }); ?>
