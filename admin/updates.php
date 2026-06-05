<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Updater.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requireRole('admin');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$results = [];

// ---- Reset a single migration so it can be re-run ----
if ($action === 'reset_migration' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::requireSuperAdmin();
    $version = preg_replace('/[^a-z0-9_-]/i', '', $_POST['version'] ?? '');
    if ($version) {
        Updater::resetMigration($version);
        flash('success', "Migration {$version} has been reset and will run on next migration.");
    }
    redirect(siteUrl('admin/updates'));
}

// ---- Remove orphaned migration record (file deleted, record remains) ----
if ($action === 'remove_orphan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::requireSuperAdmin();
    $version = preg_replace('/[^a-z0-9_-]/i', '', $_POST['version'] ?? '');
    if ($version) {
        Updater::resetMigration($version);
        flash('success', "Orphaned record for {$version} removed.");
    }
    redirect(siteUrl('admin/updates'));
}

// ---- Reset all migrations and rerun from scratch ----
if ($action === 'rerun_all_migrations' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::requireSuperAdmin();
    $results = Updater::resetAndRerunAll();
    $ok = !empty($results) && !in_array(false, array_column($results, 'ok'));
    if ($ok) {
        flash('success', count($results) . ' migration(s) reset and reapplied successfully.');
        redirect(siteUrl('admin/updates'));
    }
    // On failure: fall through so the page renders with $results intact
}

// ---- Sync lock file version to code version ----
if ($action === 'sync_version' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::requireRole('admin');
    Updater::updateLockVersion(Updater::APP_VERSION);
    flash('success', 'Installed version updated to ' . Updater::APP_VERSION . '.');
    redirect(siteUrl('admin/updates'));
}

// ---- Clear page cache ----
if ($action === 'clear_cache' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    PageCache::init();
    PageCache::clearAll();
    flash('success', 'Page cache cleared. The public site will rebuild pages fresh on next visit.');
    redirect(siteUrl('admin/updates'));
}

// ---- Run pending migrations only ----
if ($action === 'migrate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $results = Updater::runPendingMigrations();
    if (empty($results)) {
        flash('info', 'No pending migrations to run.');
        redirect(siteUrl('admin/updates'));
    }
    $ok = !in_array(false, array_column($results, 'ok'));
    if ($ok) {
        flash('success', count($results) . ' migration(s) applied successfully.');
        redirect(siteUrl('admin/updates'));
    }
    // On failure: fall through so the page renders with $results intact
}

// ---- Git pull ----
if ($action === 'git_pull' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $pullResult = Updater::gitPull();
    if ($pullResult['ok']) {
        flash('success', 'Code updated via git. ' . count($pullResult['migrations']) . ' migration(s) run.');
    } else {
        flash('error', 'Git pull failed: ' . $pullResult['output']);
    }
    redirect(siteUrl('admin/updates'));
}

// ---- ZIP upload update ----
if ($action === 'zip_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    if (isset($_FILES['update_zip']) && $_FILES['update_zip']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['update_zip']['tmp_name'];
        $result  = Updater::applyZipUpdate($tmpFile);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        if (!empty($result['migrations'])) {
            $migOk = !in_array(false, array_column($result['migrations'], 'ok'));
            if (!$migOk) {
                flash('error', 'Some migrations failed during update.');
            }
        }
    } else {
        flash('error', 'No file uploaded or upload error.');
    }
    redirect(siteUrl('admin/updates'));
}

// ---- Data for page ----
$currentVersion = Updater::installedVersion();
$lockNeedsSync  = Updater::lockNeedsSync();
$pending        = Updater::pendingMigrations();
$applied        = Updater::appliedMigrations();
$allMigrations  = Updater::allMigrations();
$orphaned       = Updater::orphanedMigrations();
$gitStatus      = Updater::gitStatus();

// GitHub check is slow — only run when user explicitly requests it
$githubResult = null;
if ($_GET['check_github'] ?? false) {
    $githubResult = Updater::checkGitHub();
}

adminLayout('Updates & Migrations', function() use (
    $currentVersion, $lockNeedsSync, $pending, $applied, $allMigrations,
    $orphaned, $gitStatus, $githubResult, $results
) {
?>

<!-- Version banner -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); margin-bottom:<?= $lockNeedsSync ? '8px' : '24px' ?>;">
  <div class="stat-card">
    <div class="num" style="font-size:24px; color:<?= $lockNeedsSync ? '#e65100' : 'inherit' ?>;"><?= h($currentVersion) ?></div>
    <div class="label">Installed Version</div>
  </div>
  <div class="stat-card">
    <div class="num" style="font-size:24px; color:#2e7d32;"><?= h(Updater::APP_VERSION) ?></div>
    <div class="label">Code Version</div>
  </div>
  <div class="stat-card">
    <div class="num" style="font-size:24px; color:<?= count($pending) > 0 ? '#e65100' : '#2e7d32' ?>;"><?= count($pending) ?></div>
    <div class="label">Pending Migration<?= count($pending) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="stat-card">
    <div class="num" style="font-size:24px;"><?= count($applied) ?></div>
    <div class="label">Applied Migrations</div>
  </div>
</div>

<?php if ($lockNeedsSync): ?>
<div class="alert alert-warn" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px;">
  <span>
    &#9888; <strong>Installed version <?= h($currentVersion) ?> is behind code version <?= h(Updater::APP_VERSION) ?>.</strong>
    Files were deployed manually — click to mark this installation as up to date.
  </span>
  <form method="post" style="margin:0;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="sync_version">
    <button type="submit" class="btn btn-primary btn-sm">
      &#10003; Mark as <?= h(Updater::APP_VERSION) ?>
    </button>
  </form>
</div>
<?php endif; ?>

<!-- Migration run results (shown when a migration fails) -->
<?php if (!empty($results)): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><h2 class="card-title">&#128203; Migration Results</h2></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Migration</th><th>Status</th><th>Error</th></tr></thead>
      <tbody>
        <?php foreach ($results as $ver => $r): ?>
        <tr>
          <td><code style="font-size:12px;"><?= h($ver) ?></code></td>
          <td>
            <?php if ($r['ok']): ?>
              <span class="badge badge-published">&#10003; Applied</span>
            <?php else: ?>
              <span class="badge badge-draft" style="background:#fde8e8;color:#b71c1c;">&#10007; Failed</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px; color:#b71c1c; font-family:monospace; white-space:pre-wrap;">
            <?= $r['error'] ? h($r['error']) : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Pending migrations alert -->
<?php if (count($pending) > 0): ?>
<div class="alert alert-warn">
  <strong>&#9888; Database update required.</strong>
  <?= count($pending) ?> migration(s) have not been applied yet.
  Run them below before using new features.
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">

  <!-- Left column -->
  <div>

    <!-- Run migrations -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">&#128196; Database Migrations</h2>
      </div>
      <?php if (count($pending) === 0): ?>
        <div class="alert alert-success" style="margin:0;">
          &#10003; All migrations are up to date.
        </div>
      <?php else: ?>
        <p style="margin-bottom:14px; font-family:sans-serif; font-size:13.5px; color:var(--slate-lt);">
          The following migrations will be applied in order:
        </p>
        <ul style="list-style:none; margin-bottom:14px;">
          <?php foreach ($pending as $m): ?>
            <li style="padding:6px 0; border-bottom:1px solid var(--sand); font-family:monospace; font-size:13px; color:var(--brown);">
              &#9658; <?= h($m['version']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="migrate">
          <button type="submit" class="btn btn-primary" style="width:100%;">
            Run <?= count($pending) ?> Pending Migration<?= count($pending) !== 1 ? 's' : '' ?>
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Git update -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">&#128260; Git Code Update</h2>
      </div>
      <?php if (!$gitStatus['available']): ?>
        <p style="color:var(--slate-lt); font-family:sans-serif; font-size:13.5px; margin-bottom:10px;">
          Git is not available on this server or this is not a git repository.
          Use the ZIP upload method below instead.
        </p>
      <?php elseif (!Updater::isGitRepo()): ?>
        <p style="color:var(--slate-lt); font-family:sans-serif; font-size:13.5px; margin-bottom:10px;">
          This installation was not deployed via git. Use the ZIP upload method below.
        </p>
      <?php else: ?>
        <div style="background:var(--cream); border-radius:var(--radius); padding:12px; margin-bottom:14px; font-family:monospace; font-size:12.5px; line-height:1.8;">
          <div>Branch: <strong><?= h($gitStatus['branch']) ?></strong></div>
          <div>Commit: <strong><?= h($gitStatus['hash']) ?></strong></div>
          <div>Behind remote: <strong style="color:<?= $gitStatus['behind'] > 0 ? '#e65100' : '#2e7d32' ?>;">
            <?= $gitStatus['behind'] ?> commit<?= $gitStatus['behind'] !== 1 ? 's' : '' ?>
          </strong></div>
        </div>

        <?php if ($gitStatus['behind'] > 0): ?>
          <form method="post" onsubmit="return confirm('Pull the latest code from git? This will overwrite local changes.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="git_pull">
            <button type="submit" class="btn btn-primary" style="width:100%;">
              &#11015; Pull <?= $gitStatus['behind'] ?> New Commit<?= $gitStatus['behind'] !== 1 ? 's' : '' ?>
            </button>
          </form>
        <?php else: ?>
          <div class="alert alert-success" style="margin:0;">&#10003; Code is up to date.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- GitHub release check -->
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <h2 class="card-title" style="margin:0;">&#127760; Check for New Releases</h2>
        <a href="https://github.com/<?= Updater::GITHUB_REPO ?>" target="_blank"
           style="font-size:12px; color:var(--slate-lt); text-decoration:none; display:flex; align-items:center; gap:4px;">
          &#128279; BishopGreer/mcc-redeemer-cms
        </a>
      </div>
      <?php if ($githubResult === null): ?>
        <p style="color:var(--slate-lt); font-family:sans-serif; font-size:13.5px; margin-bottom:12px;">
          Check GitHub for a newer version of MCCOOR CMS.
        </p>
        <a href="?check_github=1" class="btn btn-secondary" style="width:100%; justify-content:center;">
          Check GitHub Now
        </a>
      <?php elseif (isset($githubResult['error'])): ?>
        <div class="alert alert-error">Could not check GitHub: <?= h($githubResult['error']) ?></div>
        <a href="?check_github=1" class="btn btn-secondary btn-sm">Retry</a>
      <?php elseif ($githubResult['newer']): ?>
        <div class="alert alert-warn">
          <strong>&#11015; Update available: <?= h($githubResult['tag']) ?></strong><br>
          You are running <strong><?= h(Updater::installedVersion()) ?></strong>.
        </div>
        <?php if ($githubResult['notes']): ?>
          <div style="background:var(--cream); border-radius:var(--radius); padding:12px; margin-bottom:12px; font-family:sans-serif; font-size:13px; max-height:150px; overflow-y:auto; white-space:pre-wrap; line-height:1.6;">
            <?= h($githubResult['notes']) ?>
          </div>
        <?php endif; ?>
        <a href="<?= h($githubResult['url']) ?>" target="_blank" class="btn btn-primary" style="width:100%; justify-content:center;">
          View Release on GitHub &rarr;
        </a>
      <?php else: ?>
        <div class="alert alert-success">
          &#10003; You are running the latest release (<?= h($githubResult['latest']) ?>).
        </div>
        <div style="display:flex; gap:8px; margin-top:10px;">
          <a href="?check_github=1" class="btn btn-secondary btn-sm">Re-check</a>
          <a href="https://github.com/<?= Updater::GITHUB_REPO ?>/releases" target="_blank"
             class="btn btn-secondary btn-sm">All Releases &#8599;</a>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Right column -->
  <div>

    <!-- Clear page cache -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">&#128465; Clear Page Cache</h2>
      </div>
      <?php
        $cacheDir   = BASE_PATH . '/cache/pages';
        $cacheFiles = is_dir($cacheDir) ? count(glob($cacheDir . '/*.html') ?: []) : 0;
      ?>
      <p style="color:var(--slate-lt); font-family:sans-serif; font-size:13.5px; margin-bottom:12px;">
        The site caches public pages as HTML files for speed. If you've made content changes
        that aren't showing on the public site, clear the cache to force a fresh rebuild.
      </p>
      <p style="font-size:13px; margin-bottom:14px;">
        <strong><?= $cacheFiles ?></strong> cached page<?= $cacheFiles !== 1 ? 's' : '' ?> on disk.
      </p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="clear_cache">
        <button type="submit" class="btn btn-secondary" style="width:100%;">
          &#128465; Clear All Cached Pages
        </button>
      </form>
    </div>

    <!-- ZIP upload -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">&#128230; Upload Update Package</h2>
      </div>
      <p style="color:var(--slate-lt); font-family:sans-serif; font-size:13.5px; margin-bottom:14px;">
        Upload a <code>.zip</code> update package downloaded from GitHub releases.
        The package will overwrite changed files and run any new migrations automatically.
      </p>
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="zip_update">
        <div class="form-group">
          <label>Update ZIP File</label>
          <input type="file" name="update_zip" accept=".zip,application/zip" class="form-control" required>
        </div>
        <div class="alert alert-warn" style="margin-bottom:12px; font-size:13px;">
          &#9888; Always back up your database and files before applying an update.
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;"
                onclick="return confirm('Apply this update package? Make sure you have a backup first.');">
          &#128230; Apply Update Package
        </button>
      </form>
    </div>

    <!-- Migration history -->
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <h2 class="card-title" style="margin:0;">&#128203; Migration History</h2>
        <?php if (Auth::isSuperAdmin()): ?>
        <form method="post" style="margin:0;"
              onsubmit="return confirm('This will clear ALL migration records and rerun every migration from scratch.\n\nThis is safe — all SQL files use IF NOT EXISTS — but it may take a moment.\n\nContinue?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="rerun_all_migrations">
          <button class="btn btn-sm btn-danger">&#9851; Reset &amp; Reapply All Migrations</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if (!empty($orphaned)): ?>
      <div class="alert alert-warn" style="margin:0 0 12px;">
        <strong>&#9888; Orphaned records</strong> — the following migrations are recorded as applied
        but their files no longer exist. Remove the records or restore the files.
      </div>
      <div class="table-wrap" style="margin-bottom:12px;">
        <table class="data-table">
          <thead><tr><th>Migration</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($orphaned as $v): ?>
            <tr>
              <td><code style="font-size:12px; color:#b71c1c;"><?= h($v) ?></code></td>
              <td>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Remove orphaned record for <?= h($v) ?>?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="remove_orphan">
                  <input type="hidden" name="version" value="<?= h($v) ?>">
                  <button class="btn btn-sm btn-danger">Remove Record</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Migration</th><th>Status</th><?php if (Auth::isSuperAdmin()): ?><th></th><?php endif; ?></tr>
          </thead>
          <tbody>
            <?php if (empty($allMigrations)): ?>
              <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px;">No migration files found.</td></tr>
            <?php endif; ?>
            <?php foreach (array_reverse($allMigrations) as $m):
              $isApplied = in_array($m['version'], $applied, true);
            ?>
              <tr>
                <td><code style="font-size:12px;"><?= h($m['version']) ?></code></td>
                <td>
                  <?php if ($isApplied): ?>
                    <span class="badge badge-published">&#10003; Applied</span>
                  <?php else: ?>
                    <span class="badge badge-draft">Pending</span>
                  <?php endif; ?>
                </td>
                <?php if (Auth::isSuperAdmin()): ?>
                <td>
                  <?php if ($isApplied): ?>
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('Reset <?= h($m['version']) ?>? It will be re-run on next migration.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_migration">
                    <input type="hidden" name="version" value="<?= h($m['version']) ?>">
                    <button class="btn btn-sm btn-secondary">Reset</button>
                  </form>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- System info -->
    <div class="card">
      <div class="card-header"><h2 class="card-title">&#9881; System Information</h2></div>
      <table class="data-table" style="font-size:12.5px;">
        <tbody>
          <tr><td style="color:var(--slate-lt);">PHP Version</td><td><?= h(PHP_VERSION) ?></td></tr>
          <?php
            $dbVersion = 'Unknown';
            try {
                $row = Database::fetch("SELECT VERSION() AS v");
                if ($row) $dbVersion = $row['v'];
            } catch (\Throwable) {}
          ?>
          <tr><td style="color:var(--slate-lt);">MariaDB / MySQL</td><td><?= h($dbVersion) ?></td></tr>
          <tr><td style="color:var(--slate-lt);">Web Server</td><td><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></td></tr>
          <tr><td style="color:var(--slate-lt);">OS</td><td><?= h(PHP_OS_FAMILY) ?></td></tr>
          <tr><td style="color:var(--slate-lt);">PHP Memory Limit</td><td><?= h(ini_get('memory_limit')) ?></td></tr>
          <tr><td style="color:var(--slate-lt);">Max Upload Size</td><td><?= h(ini_get('upload_max_filesize')) ?></td></tr>
          <tr><td style="color:var(--slate-lt);">GD Extension</td><td><?= extension_loaded('gd') ? '&#10003; Yes' : '&#10007; No' ?></td></tr>
          <tr><td style="color:var(--slate-lt);">Zip Extension</td><td><?= extension_loaded('zip') ? '&#10003; Yes' : '&#10007; No' ?></td></tr>
          <tr><td style="color:var(--slate-lt);">cURL Extension</td><td><?= extension_loaded('curl') ? '&#10003; Yes' : '&#10007; No' ?></td></tr>
          <tr><td style="color:var(--slate-lt);">Git Available</td><td><?= Updater::gitAvailable() ? '&#10003; Yes' : 'No' ?></td></tr>
          <tr><td style="color:var(--slate-lt);">Is Git Repo</td><td><?= Updater::isGitRepo() ? '&#10003; Yes' : 'No' ?></td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php }); ?>
