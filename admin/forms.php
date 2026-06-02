<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Forms.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

if (!Auth::can('admin') && !Auth::hasPermission('manage_contacts')) {
    http_response_code(403);
    adminLayout('Forms', fn() => print('<div class="alert alert-error">Access denied.</div>'));
    exit;
}

// Guard: migration may not have run yet
$tablesOk = Database::fetch("SHOW TABLES LIKE 'custom_forms'");
if (!$tablesOk) {
    adminLayout('Forms', function () {
        ?>
        <div class="alert alert-error">
            The <strong>custom_forms</strong> table is missing.
            Go to <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
            and run pending migrations, then return here.
        </div>
        <?php
    });
    exit;
}

$siteId = Database::siteId();
$formId = (int) ($_GET['id']  ?? 0);
$subId  = (int) ($_GET['sub'] ?? 0);
$print  = !empty($_GET['print']) && $subId;

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $action = $_POST['action'] ?? '';

    // --- Import Forminator ---
    if ($action === 'import_forminator') {
        // Accept either a file upload or pasted JSON
        $json = '';

        if (!empty($_FILES['forminator_file']['tmp_name']) && $_FILES['forminator_file']['error'] === UPLOAD_ERR_OK) {
            $json = file_get_contents($_FILES['forminator_file']['tmp_name']);
        } elseif (!empty($_POST['forminator_json'])) {
            $json = trim($_POST['forminator_json']);
        }

        if (!$json) {
            flash('error', 'Upload the Forminator .txt export file or paste the JSON below.');
        } else {
            $res = Forms::importForminator($json, $siteId);
            if (isset($res['error'])) {
                flash('error', $res['error']);
            } else {
                flash('success', 'Form imported (ID ' . $res['form_id'] . '). Set its status to Published when ready.');
                redirect(siteUrl('admin/forms/' . $res['form_id']));
            }
        }
        redirect(siteUrl('admin/forms?import=1'));
    }

    // --- Import CF7 ---
    if ($action === 'import_cf7') {
        $markup = trim($_POST['cf7_markup'] ?? '');
        $title  = trim($_POST['cf7_title']  ?? '');
        if (!$markup || !$title) {
            flash('error', 'Provide both a title and the CF7 form markup.');
        } else {
            $res = Forms::importCF7($markup, $title, $siteId);
            if (isset($res['error'])) {
                flash('error', $res['error']);
            } else {
                flash('success', 'CF7 form imported (ID ' . $res['form_id'] . ').');
                redirect(siteUrl('admin/forms/' . $res['form_id']));
            }
        }
        redirect(siteUrl('admin/forms?import=1'));
    }

    // --- Toggle status ---
    if ($action === 'toggle_status') {
        $tid  = (int) ($_POST['form_id'] ?? 0);
        $row  = Database::fetch("SELECT id, status FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
        if ($row) {
            $new = $row['status'] === 'published' ? 'draft' : 'published';
            Database::update('custom_forms', ['status' => $new], 'id = ?', [$tid]);
            flash('success', 'Form ' . ($new === 'published' ? 'published' : 'set to draft') . '.');
        }
        redirect(siteUrl('admin/forms'));
    }

    // --- Toggle requires_login ---
    if ($action === 'toggle_login') {
        $tid = (int) ($_POST['form_id'] ?? 0);
        $row = Database::fetch("SELECT id, requires_login FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
        if ($row) {
            $new = $row['requires_login'] ? 0 : 1;
            Database::update('custom_forms', ['requires_login' => $new], 'id = ?', [$tid]);
            flash('success', 'Login requirement ' . ($new ? 'enabled' : 'disabled') . '.');
        }
        redirect(siteUrl('admin/forms/' . $tid));
    }

    // --- Toggle hCaptcha ---
    if ($action === 'toggle_hcaptcha') {
        $tid = (int) ($_POST['form_id'] ?? 0);
        $row = Database::fetch("SELECT id, use_hcaptcha FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
        if ($row) {
            $new = $row['use_hcaptcha'] ? 0 : 1;
            Database::update('custom_forms', ['use_hcaptcha' => $new], 'id = ?', [$tid]);
            flash('success', 'hCaptcha ' . ($new ? 'enabled' : 'disabled') . '.');
        }
        redirect(siteUrl('admin/forms/' . $tid));
    }

    // --- Update notify email ---
    if ($action === 'update_notify') {
        $tid   = (int) ($_POST['form_id'] ?? 0);
        $email = trim($_POST['notify_email'] ?? '');
        $smsg  = trim($_POST['success_msg']  ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid notification email address.');
        } else {
            Database::update('custom_forms', [
                'notify_email' => $email ?: null,
                'success_msg'  => $smsg ?: 'Thank you — your form has been submitted successfully.',
            ], 'id = ? AND site_id = ?', [$tid, $siteId]);
            flash('success', 'Settings saved.');
        }
        redirect(siteUrl('admin/forms/' . $tid));
    }

    // --- Delete submission ---
    if ($action === 'delete_submission') {
        $sid = (int) ($_POST['sub_id'] ?? 0);
        if ($sid) {
            // Remove uploaded files
            $files = Database::fetchAll("SELECT stored_name FROM form_files WHERE submission_id = ?", [$sid]);
            $dir   = BASE_PATH . '/public/uploads/form-files/' . $sid . '/';
            foreach ($files as $ff) {
                @unlink($dir . $ff['stored_name']);
            }
            @rmdir($dir);
            Database::query("DELETE FROM form_files WHERE submission_id = ?", [$sid]);
            Database::query("DELETE FROM form_submissions WHERE id = ? AND site_id = ?", [$sid, $siteId]);
            flash('success', 'Submission deleted.');
        }
        $redirect = $formId ? siteUrl('admin/forms/' . $formId) : siteUrl('admin/forms');
        redirect($redirect);
    }

    // --- Delete form ---
    if ($action === 'delete_form') {
        $tid = (int) ($_POST['form_id'] ?? 0);
        if ($tid) {
            $formRow = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
            // Remove nav page entry if one was created
            if ($formRow && !empty($formRow['nav_page_id'])) {
                Database::query("DELETE FROM pages WHERE id = ? AND site_id = ?", [$formRow['nav_page_id'], $siteId]);
            }
            // Remove all submission files
            $subs = Database::fetchAll("SELECT id FROM form_submissions WHERE form_id = ? AND site_id = ?", [$tid, $siteId]);
            foreach ($subs as $s) {
                $dir   = BASE_PATH . '/public/uploads/form-files/' . $s['id'] . '/';
                $files = Database::fetchAll("SELECT stored_name FROM form_files WHERE submission_id = ?", [$s['id']]);
                foreach ($files as $ff) {
                    @unlink($dir . $ff['stored_name']);
                }
                @rmdir($dir);
                Database::query("DELETE FROM form_files WHERE submission_id = ?", [$s['id']]);
            }
            Database::query("DELETE FROM form_submissions WHERE form_id = ? AND site_id = ?", [$tid, $siteId]);
            Database::query("DELETE FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
            flash('success', 'Form and all its submissions deleted.');
        }
        redirect(siteUrl('admin/forms'));
    }

    // --- Mark submission read ---
    if ($action === 'mark_read') {
        $sid = (int) ($_POST['sub_id'] ?? 0);
        if ($sid) {
            Database::update('form_submissions', ['is_read' => 1], 'id = ? AND site_id = ?', [$sid, $siteId]);
        }
        redirect(siteUrl('admin/forms/' . $formId . '?sub=' . $sid));
    }

    // --- Add form to navigation ---
    if ($action === 'nav_add') {
        $tid = (int) ($_POST['form_id'] ?? 0);
        $row = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
        if ($row && !($row['nav_page_id'] ?? 0)) {
            // Find the highest current menu_order for this site
            $maxOrder = Database::fetch(
                "SELECT MAX(menu_order) AS m FROM pages WHERE site_id = ?", [$siteId]
            )['m'] ?? 0;

            $parentId = (int) ($_POST['nav_parent_id'] ?? 0);
            $navLabel = trim($_POST['nav_label'] ?? '') ?: $row['title'];

            $pageId = Database::insert('pages', [
                'site_id'    => $siteId,
                'title'      => $row['title'],
                'nav_label'  => $navLabel,
                'slug'       => '_form-nav-' . $row['id'],
                'page_type'  => 'link',
                'link_url'   => '/forms/' . $row['slug'],
                'status'     => 'published',
                'show_in_nav'=> 1,
                'parent_id'  => $parentId ?: null,
                'menu_order' => (int)$maxOrder + 10,
                'author_id'  => Auth::user()['id'],
            ]);
            Database::update('custom_forms', ['nav_page_id' => $pageId], 'id = ?', [$tid]);
            flash('success', 'Form added to navigation menu.');
        }
        redirect(siteUrl('admin/forms/' . $tid));
    }

    // --- Remove form from navigation ---
    if ($action === 'nav_remove') {
        $tid = (int) ($_POST['form_id'] ?? 0);
        $row = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$tid, $siteId]);
        if ($row && ($row['nav_page_id'] ?? 0)) {
            Database::query("DELETE FROM pages WHERE id = ? AND site_id = ?", [$row['nav_page_id'], $siteId]);
            Database::update('custom_forms', ['nav_page_id' => null], 'id = ?', [$tid]);
            flash('success', 'Form removed from navigation menu.');
        }
        redirect(siteUrl('admin/forms/' . $tid));
    }

    redirect(siteUrl('admin/forms'));
}

// ============================================================
// PRINT / PDF VIEW  — standalone full HTML page
// ============================================================
if ($print && $formId && $subId) {
    $form = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$formId, $siteId]);
    $sub  = Database::fetch("SELECT * FROM form_submissions WHERE id = ? AND form_id = ? AND site_id = ?", [$subId, $formId, $siteId]);

    if (!$form || !$sub) {
        http_response_code(404);
        echo '<h1>Not Found</h1>';
        exit;
    }

    // Mark as read
    if (!$sub['is_read']) {
        Database::update('form_submissions', ['is_read' => 1], 'id = ?', [$subId]);
    }

    $siteName = setting('site_name', 'Your Parish');
    $data     = json_decode($sub['data_json'] ?? '[]', true) ?: [];
    $fields   = json_decode($form['fields_json'] ?? '[]', true) ?: [];
    $flat     = Forms::flattenFields($fields);
    $files    = Database::fetchAll("SELECT * FROM form_files WHERE submission_id = ?", [$subId]);
    $filesByField = [];
    foreach ($files as $ff) {
        $filesByField[$ff['field_id']][] = $ff;
    }

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($form['title']) ?> — Submission #<?= $subId ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
  font-family: Georgia, 'Times New Roman', serif;
  font-size: 11pt;
  color: #1a1a1a;
  margin: 0;
  padding: 0;
  background: #fff;
}
.no-print {
  position: fixed; top: 0; left: 0; right: 0;
  background: #2c3e50; color: #fff;
  padding: 10px 20px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; z-index: 999; font-family: sans-serif; font-size: 13px;
}
.no-print button {
  background: #27ae60; color: #fff; border: none;
  padding: 7px 18px; border-radius: 4px; cursor: pointer; font-size: 13px;
}
.no-print a { color: #aaa; text-decoration: none; font-size: 12px; }
.page {
  max-width: 750px;
  margin: 60px auto 40px;
  padding: 40px 50px;
  background: #fff;
}
.letterhead {
  border-bottom: 3px solid #5d4037;
  padding-bottom: 16px;
  margin-bottom: 24px;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
}
.letterhead h1 {
  font-size: 20pt;
  color: #5d4037;
  margin: 0 0 2px;
  font-weight: normal;
  letter-spacing: .03em;
}
.letterhead .tagline {
  font-size: 10pt;
  color: #888;
  font-style: italic;
}
.letterhead .meta {
  text-align: right;
  font-size: 10pt;
  color: #666;
  font-family: sans-serif;
}
.form-title {
  font-size: 16pt;
  color: #3c2000;
  margin: 0 0 4px;
  border-bottom: 1px solid #e0d6cc;
  padding-bottom: 8px;
}
.sub-meta {
  font-size: 9.5pt;
  color: #888;
  font-family: sans-serif;
  margin-bottom: 24px;
}
.section-header {
  font-size: 12pt;
  font-weight: bold;
  color: #5d4037;
  margin: 24px 0 8px;
  padding: 4px 0;
  border-bottom: 1px solid #e0d6cc;
  text-transform: uppercase;
  letter-spacing: .06em;
  page-break-after: avoid;
}
.field-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 6px 16px;
  padding: 7px 0;
  border-bottom: 1px solid #f0ebe5;
  page-break-inside: avoid;
  font-size: 10.5pt;
}
.field-label {
  color: #666;
  font-family: sans-serif;
  font-size: 9pt;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding-top: 1px;
}
.field-value {
  color: #1a1a1a;
  line-height: 1.5;
}
.files-section {
  margin-top: 24px;
  padding-top: 12px;
  border-top: 1px solid #e0d6cc;
  font-family: sans-serif;
  font-size: 10pt;
}
.files-section h4 { color: #5d4037; margin: 0 0 8px; font-size: 11pt; }
.file-item { padding: 4px 0; color: #333; }
.footer {
  margin-top: 40px;
  padding-top: 12px;
  border-top: 1px solid #e0d6cc;
  font-size: 8.5pt;
  color: #aaa;
  font-family: sans-serif;
  text-align: center;
}
@media print {
  .no-print { display: none !important; }
  .page { margin: 0; padding: 20px 30px; max-width: none; }
  body { font-size: 10pt; }
  .section-header { page-break-after: avoid; }
  .field-row { page-break-inside: avoid; }
}
</style>
</head>
<body>

<div class="no-print">
  <span>&#128438; Submission #<?= $subId ?> &mdash; <?= h($form['title']) ?></span>
  <span style="display:flex;gap:12px;align-items:center;">
    <a href="<?= siteUrl('admin/forms/' . $formId . '?sub=' . $subId) ?>">&larr; Back to Admin</a>
    <button onclick="window.print()">&#128438; Print / Save as PDF</button>
  </span>
</div>

<div class="page">

  <div class="letterhead">
    <div>
      <div class="h1"><?= h($siteName) ?></div>
      <div class="tagline"><?= h(setting('site_tagline', 'A Community of Faith')) ?></div>
    </div>
    <div class="meta">
      Submission #<?= $subId ?><br>
      <?= date('F j, Y', strtotime($sub['submitted_at'])) ?><br>
      <?= date('g:i a', strtotime($sub['submitted_at'])) ?>
    </div>
  </div>

  <h2 class="form-title"><?= h($form['title']) ?></h2>
  <div class="sub-meta">
    Submitted by IP <?= h($sub['ip_address'] ?? 'unknown') ?>
    &bull; <?= date('l, F j, Y \a\t g:i a', strtotime($sub['submitted_at'])) ?>
  </div>

  <?php foreach ($flat as $f):
    if ($f['type'] === 'group'): ?>
    <div class="section-header"><?= h($f['label']) ?></div>
    <?php continue;
    endif;

    $val = $data[$f['id']] ?? null;
    if ($val === null || $val === '' || $val === []) {
        continue; // Skip blank fields
    }
  ?>
  <div class="field-row">
    <div class="field-label"><?= h($f['label']) ?></div>
    <div class="field-value"><?= Forms::formatValue($f, $val) ?></div>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($files)): ?>
  <div class="files-section">
    <h4>Uploaded Files</h4>
    <?php foreach ($files as $ff): ?>
    <div class="file-item">
      &#128196; <?= h($ff['original_name']) ?>
      <span style="color:#aaa;font-size:9pt;">
        (<?= $ff['file_size'] ? round($ff['file_size'] / 1024, 1) . ' KB' : '' ?>)
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="footer">
    <?= h($siteName) ?> &mdash; Confidential Document &mdash; <?= date('Y') ?>
    <br>Pax et Bonum
  </div>

</div>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// SINGLE SUBMISSION VIEW
// ============================================================
if ($formId && $subId) {
    $form = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$formId, $siteId]);
    $sub  = Database::fetch("SELECT * FROM form_submissions WHERE id = ? AND form_id = ? AND site_id = ?", [$subId, $formId, $siteId]);

    if (!$form || !$sub) {
        flash('error', 'Submission not found.');
        redirect(siteUrl('admin/forms/' . $formId));
    }

    // Auto-mark as read
    if (!$sub['is_read']) {
        Database::update('form_submissions', ['is_read' => 1], 'id = ?', [$subId]);
        $sub['is_read'] = 1;
    }

    $data  = json_decode($sub['data_json'] ?? '[]', true) ?: [];
    $fields = json_decode($form['fields_json'] ?? '[]', true) ?: [];
    $flat  = Forms::flattenFields($fields);
    $files = Database::fetchAll("SELECT * FROM form_files WHERE submission_id = ?", [$subId]);
    $filesByField = [];
    foreach ($files as $ff) {
        $filesByField[$ff['field_id']][] = $ff;
    }

    adminLayout('Submission #' . $subId, function () use ($form, $sub, $data, $flat, $files, $filesByField, $formId, $subId, $siteId) {
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div>
                <a href="<?= siteUrl('admin/forms/' . $formId) ?>" class="btn btn-secondary btn-sm">&larr; All Submissions</a>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="<?= siteUrl('admin/forms/' . $formId . '?sub=' . $subId . '&print=1') ?>"
                   target="_blank" class="btn btn-primary btn-sm">&#128438; Print / PDF</a>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete this submission permanently?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_submission">
                    <input type="hidden" name="sub_id" value="<?= $subId ?>">
                    <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Delete</button>
                </form>
            </div>
        </div>

        <div class="card" style="max-width:860px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 class="card-title"><?= h($form['title']) ?> — Submission #<?= $subId ?></h2>
                <span style="font-size:12px;color:var(--slate-lt);font-family:sans-serif;">
                    <?= date('F j, Y \a\t g:i a', strtotime($sub['submitted_at'])) ?>
                </span>
            </div>
            <div style="padding:0 0 4px;font-size:12px;color:#aaa;font-family:sans-serif;">
                IP: <?= h($sub['ip_address'] ?? 'unknown') ?>
            </div>

            <div style="margin-top:16px;">
            <?php foreach ($flat as $f):
                if ($f['type'] === 'group'): ?>
                    <div style="margin:20px 0 8px;padding:6px 0;border-bottom:2px solid #e0d6cc;
                                font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                                color:#5d4037;font-size:12px;">
                        <?= h($f['label']) ?>
                    </div>
                <?php continue;
                endif;

                $val = $data[$f['id']] ?? null;
                if ($val === null || $val === '' || $val === []) continue;
                $hasFiles = isset($filesByField[$f['id']]);
            ?>
            <div style="display:grid;grid-template-columns:200px 1fr;gap:6px 16px;
                        padding:8px 0;border-bottom:1px solid #f0ebe5;font-size:14px;">
                <div style="color:#888;font-size:11px;font-weight:600;text-transform:uppercase;
                            letter-spacing:.04em;padding-top:2px;font-family:sans-serif;">
                    <?= h($f['label']) ?>
                </div>
                <div style="line-height:1.6;">
                    <?= Forms::formatValue($f, $val) ?>
                    <?php if ($hasFiles): ?>
                    <div style="margin-top:6px;">
                        <?php foreach ($filesByField[$f['id']] as $ff): ?>
                        <div style="font-size:12px;font-family:sans-serif;color:#555;margin:2px 0;">
                            &#128196;
                            <a href="<?= siteUrl('public/uploads/form-files/' . $ff['submission_id'] . '/' . $ff['stored_name']) ?>"
                               target="_blank" style="color:#3498db;">
                                <?= h($ff['original_name']) ?>
                            </a>
                            <span style="color:#aaa;">(<?= round(($ff['file_size'] ?? 0) / 1024, 1) ?> KB)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php
            // Files with no matching field label (uploaded to unlabelled fields)
            $orphanFiles = array_filter($files, fn($ff) => empty(array_filter($flat, fn($f) => $f['id'] === $ff['field_id'])));
            if ($orphanFiles): ?>
            <div style="margin-top:16px;padding-top:12px;border-top:1px solid #e0d6cc;font-family:sans-serif;font-size:13px;">
                <strong>Uploaded Files</strong>
                <?php foreach ($orphanFiles as $ff): ?>
                <div style="margin:4px 0;">
                    <a href="<?= siteUrl('public/uploads/form-files/' . $ff['submission_id'] . '/' . $ff['stored_name']) ?>"
                       target="_blank">&#128196; <?= h($ff['original_name']) ?></a>
                    <span style="color:#aaa;">(<?= round(($ff['file_size'] ?? 0) / 1024, 1) ?> KB)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    });
    exit;
}

// ============================================================
// FORM DETAIL — submissions list
// ============================================================
if ($formId) {
    $form = Database::fetch("SELECT * FROM custom_forms WHERE id = ? AND site_id = ?", [$formId, $siteId]);
    if (!$form) {
        flash('error', 'Form not found.');
        redirect(siteUrl('admin/forms'));
    }

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 30;
    $offset  = ($page - 1) * $perPage;

    $total    = Database::fetch("SELECT COUNT(*) n FROM form_submissions WHERE form_id = ? AND site_id = ?", [$formId, $siteId])['n'];
    $unread   = Database::fetch("SELECT COUNT(*) n FROM form_submissions WHERE form_id = ? AND site_id = ? AND is_read = 0", [$formId, $siteId])['n'];
    $subs     = Database::fetchAll(
        "SELECT id, is_read, ip_address, submitted_at FROM form_submissions
         WHERE form_id = ? AND site_id = ?
         ORDER BY submitted_at DESC LIMIT ? OFFSET ?",
        [$formId, $siteId, $perPage, $offset]
    );

    adminLayout($form['title'] . ' — Submissions', function () use ($form, $subs, $total, $unread, $page, $perPage, $formId, $siteId) {
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;gap:8px;align-items:center;">
                <a href="<?= siteUrl('admin/forms') ?>" class="btn btn-secondary btn-sm">&larr; All Forms</a>
                <h2 style="margin:0;font-size:16px;"><?= h($form['title']) ?></h2>
                <span class="badge badge-<?= $form['status'] === 'published' ? 'success' : 'secondary' ?>">
                    <?= h($form['status']) ?>
                </span>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

          <!-- Submissions table -->
          <div>
            <?php if (empty($subs)): ?>
            <div class="card" style="text-align:center;padding:40px;color:#aaa;">
                No submissions yet.
                <?php if ($form['status'] !== 'published'): ?>
                <br><small>Publish this form to start receiving submissions.</small>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card" style="padding:0;overflow:hidden;">
                <div style="padding:12px 16px;border-bottom:1px solid #f0ebe5;display:flex;align-items:center;gap:8px;font-family:sans-serif;font-size:13px;">
                    <strong><?= $total ?></strong> total &mdash; <strong><?= $unread ?></strong> unread
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:20px;"></th>
                            <th>Submission #</th>
                            <th>Received</th>
                            <th>IP</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subs as $s): ?>
                    <tr style="<?= !$s['is_read'] ? 'font-weight:600;background:#fffbf5;' : '' ?>">
                        <td style="text-align:center;">
                            <?= !$s['is_read'] ? '<span style="color:#e65100;font-size:10px;">&#9679;</span>' : '' ?>
                        </td>
                        <td>#<?= $s['id'] ?></td>
                        <td style="font-size:12px;color:#666;white-space:nowrap;">
                            <?= date('M j, Y g:i a', strtotime($s['submitted_at'])) ?>
                        </td>
                        <td style="font-size:12px;color:#aaa;"><?= h($s['ip_address'] ?? '') ?></td>
                        <td style="white-space:nowrap;">
                            <a href="<?= siteUrl('admin/forms/' . $formId . '?sub=' . $s['id']) ?>"
                               class="btn btn-sm btn-secondary">View</a>
                            <a href="<?= siteUrl('admin/forms/' . $formId . '?sub=' . $s['id'] . '&print=1') ?>"
                               target="_blank" class="btn btn-sm btn-secondary">PDF</a>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Delete this submission?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_submission">
                                <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= pagination($total, $page, $perPage, siteUrl('admin/forms/' . $formId . '?')) ?>
            <?php endif; ?>
          </div>

          <!-- Form settings panel -->
          <div>
            <div class="card" style="margin-bottom:16px;">
                <h3 class="card-title" style="margin-top:0;">Form Settings</h3>

                <?php $pubUrl = siteUrl('forms/' . $form['slug']); ?>
                <div style="font-size:12px;margin-bottom:12px;font-family:sans-serif;">
                    <strong>Public URL:</strong><br>
                    <a href="<?= h($pubUrl) ?>" target="_blank" style="word-break:break-all;color:#3498db;"><?= h($pubUrl) ?></a>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $form['status'] === 'published' ? 'btn-secondary' : 'btn-primary' ?>">
                            <?= $form['status'] === 'published' ? 'Unpublish' : 'Publish' ?>
                        </button>
                    </form>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_login">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <?= $form['requires_login'] ? 'Remove Login Req.' : 'Require Login' ?>
                        </button>
                    </form>
                    <?php if (setting('hcaptcha_site_key')): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_hcaptcha">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">
                            hCaptcha: <?= $form['use_hcaptcha'] ? 'ON' : 'OFF' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ($form['requires_login']): ?>
                <div style="font-size:11px;background:#fff3e0;padding:6px 10px;border-radius:4px;color:#e65100;margin-bottom:12px;">
                    &#128274; Login required to access this form
                </div>
                <?php endif; ?>

                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_notify">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label style="font-size:12px;">Notification Email</label>
                        <input type="email" name="notify_email" value="<?= h($form['notify_email'] ?? '') ?>"
                               placeholder="admin@example.com" style="font-size:13px;">
                    </div>
                    <div class="form-group" style="margin-bottom:10px;">
                        <label style="font-size:12px;">Success Message</label>
                        <textarea name="success_msg" rows="2" style="font-size:13px;"><?= h($form['success_msg'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
                </form>

                <hr style="border:none;border-top:1px solid #f0ebe5;margin:16px 0;">

                <form method="post" onsubmit="return confirm('Delete this entire form and ALL its submissions? This cannot be undone.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;width:100%;">
                        Delete Form &amp; All Submissions
                    </button>
                </form>
            </div>

            <!-- Navigation Management -->
            <?php if (array_key_exists('nav_page_id', $form)): ?>
            <div class="card" style="margin-bottom:16px;">
                <h3 class="card-title" style="margin-top:0;font-size:14px;">&#9776; Navigation Menu</h3>

                <?php if ($form['status'] === 'published' && !$form['nav_page_id']): ?>
                <div style="font-size:12px;background:#e8f5e9;padding:8px 10px;border-radius:4px;color:#2e7d32;margin-bottom:10px;">
                    &#10003; This form automatically appears under the <strong>Forms</strong> menu.
                </div>
                <?php elseif ($form['status'] !== 'published'): ?>
                <div style="font-size:12px;background:#fff3e0;padding:8px 10px;border-radius:4px;color:#e65100;margin-bottom:10px;">
                    Publish this form to make it appear in the <strong>Forms</strong> menu automatically.
                </div>
                <?php endif; ?>

                <?php if ($form['nav_page_id']): ?>
                    <div style="font-size:12px;background:#e3f2fd;padding:8px 10px;border-radius:4px;color:#1565c0;margin-bottom:10px;">
                        &#9650; Also placed under a parent page in the nav
                        (replaces automatic Forms listing).
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="<?= siteUrl('admin/pages/' . (int)$form['nav_page_id'] . '/edit') ?>"
                           class="btn btn-sm btn-secondary">Edit Position / Label</a>
                        <form method="post"
                              onsubmit="return confirm('Remove this form from its custom nav location? It will return to the Forms dropdown automatically.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="nav_remove">
                            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                            <button type="submit" class="btn btn-sm"
                                    style="background:#dc3545;color:#fff;">Remove Custom Placement</button>
                        </form>
                    </div>
                <?php else: ?>
                    <p style="font-size:12px;color:#666;margin:0 0 10px;">
                        To place this form under a different parent page instead of the Forms dropdown,
                        choose a parent below.
                    </p>
                    <?php
                    $navParentOptions = Database::fetchAll(
                        "SELECT id, title, nav_label FROM pages
                         WHERE site_id = ? AND show_in_nav = 1 AND status = 'published'
                           AND (parent_id = 0 OR parent_id IS NULL)
                         ORDER BY menu_order ASC",
                        [$siteId]
                    );
                    ?>
                    <?php if ($navParentOptions): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="nav_add">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px;font-weight:600;">Menu Label</label>
                            <input type="text" name="nav_label" value="<?= h($form['title']) ?>"
                                   style="font-size:12px;">
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:11px;font-weight:600;">Place under parent page</label>
                            <select name="nav_parent_id" style="font-size:12px;">
                                <option value="0">— Top level (no parent) —</option>
                                <?php foreach ($navParentOptions as $np): ?>
                                <option value="<?= $np['id'] ?>">
                                    <?= h($np['nav_label'] ?: $np['title']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Place in Nav</button>
                    </form>
                    <?php else: ?>
                    <p style="font-size:12px;color:#aaa;">No top-level nav pages found to use as a parent.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card" style="margin-bottom:16px;font-size:12px;color:#888;">
                <strong>Navigation:</strong> Run
                <a href="<?= siteUrl('admin/updates') ?>">pending migrations</a>
                to enable nav management.
            </div>
            <?php endif; ?>

            <div class="card" style="font-size:12px;color:#888;">
                <strong>Imported from:</strong> <?= h($form['imported_from'] ?? 'manual') ?><br>
                <strong>Created:</strong> <?= date('M j, Y', strtotime($form['created_at'])) ?>
            </div>
          </div>

        </div>
        <?php
    });
    exit;
}

// ============================================================
// FORMS LIST  +  IMPORT PANEL
// ============================================================
$showImport = !empty($_GET['import']);
$forms      = Database::fetchAll(
    "SELECT f.*, (SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = f.id) AS sub_count,
            (SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = f.id AND s.is_read = 0) AS unread_count
     FROM custom_forms f WHERE f.site_id = ? ORDER BY f.created_at DESC",
    [$siteId]
);

adminLayout('Forms', function () use ($forms, $showImport) {
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;font-size:18px;">Custom Forms</h2>
        <a href="<?= siteUrl('admin/forms?import=1') ?>" class="btn btn-primary">+ Import Form</a>
    </div>

    <?php if ($showImport): ?>
    <div class="card" style="margin-bottom:24px;max-width:700px;">
        <h3 class="card-title" style="margin-top:0;">Import a Form</h3>
        <p style="font-size:13px;color:#666;margin-top:0;">
            Import an existing Forminator export (JSON) or Contact Form 7 markup.
            Imported forms start in <strong>Draft</strong> status — publish when ready.
        </p>

        <details open style="margin-bottom:20px;">
            <summary style="cursor:pointer;font-weight:600;font-size:14px;padding:8px 0;">
                Forminator JSON Import
            </summary>
            <p style="font-size:12px;color:#888;margin:8px 0;">
                In Forminator: <strong>All Forms &rarr; your form &rarr; Export</strong>.
                Download the <code>.txt</code> file and upload it here.
                One form per upload &mdash; repeat for each form.
            </p>
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="import_forminator">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Upload Forminator Export (.txt)</label>
                    <input type="file" name="forminator_file" accept=".txt,.json"
                           style="margin-bottom:8px;">
                </div>
                <details style="margin-bottom:10px;">
                    <summary style="font-size:12px;color:#888;cursor:pointer;">
                        Or paste JSON manually (for small forms)
                    </summary>
                    <div class="form-group" style="margin-top:8px;">
                        <textarea name="forminator_json" rows="6"
                                  placeholder='{"type":"form","data":{...}}'
                                  style="font-family:monospace;font-size:11px;"></textarea>
                    </div>
                </details>
                <button type="submit" class="btn btn-primary">Import Forminator Form</button>
            </form>
        </details>

        <details>
            <summary style="cursor:pointer;font-weight:600;font-size:14px;padding:8px 0;">
                Contact Form 7 Import
            </summary>
            <p style="font-size:12px;color:#888;margin:8px 0;">
                From CF7: open your form &rarr; Form tab &rarr; copy the field shortcodes.
                Paste the markup below (not the full shortcode, just the field tags like
                <code>[text* your-name "Your Name"]</code>).
            </p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="import_cf7">
                <div class="form-group">
                    <label>Form Title</label>
                    <input type="text" name="cf7_title" placeholder="My Contact Form">
                </div>
                <div class="form-group">
                    <label>CF7 Form Markup</label>
                    <textarea name="cf7_markup" rows="8"
                              placeholder="[text* your-name &quot;Your Name&quot;]&#10;[email* your-email &quot;Email Address&quot;]&#10;[textarea your-message &quot;Message&quot;]"
                              style="font-family:monospace;font-size:11px;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Import CF7 Form</button>
            </form>
        </details>
    </div>
    <?php endif; ?>

    <?php if (empty($forms)): ?>
    <div class="card" style="text-align:center;padding:50px;color:#aaa;">
        <div style="font-size:32px;margin-bottom:12px;">&#128196;</div>
        <p>No forms yet. Import one from Forminator or Contact Form 7 to get started.</p>
        <a href="<?= siteUrl('admin/forms?import=1') ?>" class="btn btn-primary">Import a Form</a>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Form Name</th>
                    <th>Status</th>
                    <th>Submissions</th>
                    <th>Login Required</th>
                    <th>Imported From</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($forms as $f): ?>
            <tr>
                <td>
                    <a href="<?= siteUrl('admin/forms/' . $f['id']) ?>" style="font-weight:600;">
                        <?= h($f['title']) ?>
                    </a>
                    <div style="font-size:11px;color:#aaa;font-family:monospace;">/forms/<?= h($f['slug']) ?></div>
                </td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;
                                 background:<?= $f['status'] === 'published' ? '#e8f5e9' : '#f5f5f5' ?>;
                                 color:<?= $f['status'] === 'published' ? '#2e7d32' : '#888' ?>;">
                        <?= h($f['status']) ?>
                    </span>
                </td>
                <td>
                    <a href="<?= siteUrl('admin/forms/' . $f['id']) ?>">
                        <?= (int) $f['sub_count'] ?> total
                    </a>
                    <?php if ($f['unread_count'] > 0): ?>
                    <span style="background:#e65100;color:#fff;border-radius:10px;font-size:10px;padding:1px 6px;font-family:sans-serif;font-weight:700;">
                        <?= (int) $f['unread_count'] ?> new
                    </span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#888;">
                    <?= $f['requires_login'] ? '&#128274; Yes' : '&mdash;' ?>
                </td>
                <td style="font-size:12px;color:#aaa;">
                    <?= h($f['imported_from'] ?? 'manual') ?>
                </td>
                <td style="font-size:12px;color:#aaa;white-space:nowrap;">
                    <?= date('M j, Y', strtotime($f['created_at'])) ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="<?= siteUrl('admin/forms/' . $f['id']) ?>" class="btn btn-sm btn-secondary">View</a>
                    <a href="<?= siteUrl('forms/' . $f['slug']) ?>" target="_blank" class="btn btn-sm btn-secondary">&#127760;</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php
});
