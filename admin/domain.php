<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requireRole('admin');

// -------------------------------------------------------
// How many content items reference the old domain?
// -------------------------------------------------------
function countReferences(string $oldUrl): array {
    $like = '%' . $oldUrl . '%';
    return [
        'pages'       => (int) Database::fetch("SELECT COUNT(*) c FROM pages WHERE content LIKE ?", [$like])['c'],
        'posts'       => (int) Database::fetch("SELECT COUNT(*) c FROM posts WHERE content LIKE ?", [$like])['c'],
    ];
}

// -------------------------------------------------------
// Run the migration
// -------------------------------------------------------
function migrateUrls(string $oldUrl, string $newUrl): array {
    $results  = [];
    $oldUrl   = rtrim($oldUrl, '/');
    $newUrl   = rtrim($newUrl, '/');

    // Also handle http variant of the old URL in case mixed
    $oldHttp  = str_replace('https://', 'http://', $oldUrl);

    $tables = [
        'pages'       => ['content', 'excerpt', 'meta_desc'],
        'posts'       => ['content', 'excerpt', 'meta_desc'],
        'media'       => ['path', 'thumb_path'],
    ];

    foreach ($tables as $table => $cols) {
        $updated = 0;
        foreach ($cols as $col) {
            // Replace https://old and http://old → new
            $stmt = Database::query(
                "UPDATE `$table` SET `$col` = REPLACE(REPLACE(`$col`, ?, ?), ?, ?) WHERE `$col` LIKE ?",
                [$oldUrl, $newUrl, $oldHttp, $newUrl, '%' . $oldUrl . '%']
            );
            $updated += $stmt->rowCount();

            // Second pass: catch any remaining http variants
            $stmt2 = Database::query(
                "UPDATE `$table` SET `$col` = REPLACE(`$col`, ?, ?) WHERE `$col` LIKE ?",
                [$oldHttp, $newUrl, '%' . $oldHttp . '%']
            );
            $updated += $stmt2->rowCount();
        }
        $results[$table] = $updated;
    }

    // Update the canonical site_url in settings.
    // In network mode every subsite has its own site_url (e.g. https://osv.myocci.org),
    // so we do a domain-part replacement across all rows rather than setting them all
    // to the main domain URL.
    if (defined('NETWORK_MODE') && NETWORK_MODE) {
        $oldDomain = parse_url($oldUrl, PHP_URL_HOST) ?? '';
        $newDomain = parse_url($newUrl, PHP_URL_HOST) ?? '';
        if ($oldDomain && $newDomain) {
            Database::query(
                "UPDATE settings SET `value` = REPLACE(`value`, ?, ?) WHERE `key` = 'site_url' AND `value` LIKE ?",
                [$oldDomain, $newDomain, '%' . $oldDomain . '%']
            );
        }
    } else {
        Database::query(
            "UPDATE settings SET `value` = ? WHERE `key` = 'site_url' AND site_id = ?",
            [$newUrl, Database::siteId()]
        );
    }
    $results['settings'] = 1;

    // Lock SITE_URL in config.local.php — but ONLY in single-site mode.
    // In network mode SITE_URL must auto-detect from HTTP_HOST so each subdomain
    // gets its own URL. Writing a fixed value here would break all subsites.
    $localConfig = BASE_PATH . '/config/config.local.php';
    if (!defined('NETWORK_MODE') || !NETWORK_MODE) {
        if (file_exists($localConfig)) {
            $src = file_get_contents($localConfig);
            $newLine = "define('SITE_URL', '" . addcslashes($newUrl, "'\\") . "');";
            if (preg_match("/define\('SITE_URL'/", $src)) {
                $src = preg_replace(
                    "/\/\/\s*define\('SITE_URL'[^\n]*\n?|define\('SITE_URL'[^\n]*\n?/",
                    $newLine . "\n",
                    $src
                );
            } else {
                $src = rtrim($src) . "\n\n// Locked to production domain after migration:\n$newLine\n";
            }
            file_put_contents($localConfig, $src);
            $results['config_updated'] = true;
        }
    } else {
        // In network mode, ensure any previously locked SITE_URL is removed
        // so auto-detection works correctly for all subsites.
        if (file_exists($localConfig)) {
            $src = file_get_contents($localConfig);
            if (preg_match("/define\('SITE_URL'/", $src)) {
                $src = preg_replace(
                    "/\n?\/\/\s*define\('SITE_URL'[^\n]*\n?|define\('SITE_URL'[^\n]*\n?/",
                    "\n",
                    $src
                );
                file_put_contents($localConfig, $src);
                $results['config_removed'] = true;
            }
        }
    }

    return $results;
}

// -------------------------------------------------------
// Handle form submission
// -------------------------------------------------------
$migrationResult = null;
$previewCounts   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $oldUrl = rtrim(trim($_POST['old_url'] ?? ''), '/');
    $newUrl = rtrim(trim($_POST['new_url'] ?? ''), '/');
    $action = $_POST['action'] ?? '';

    $errors = [];
    if (!$oldUrl || !filter_var($oldUrl, FILTER_VALIDATE_URL)) $errors[] = 'Enter a valid current domain URL.';
    if (!$newUrl || !filter_var($newUrl, FILTER_VALIDATE_URL)) $errors[] = 'Enter a valid new domain URL.';
    if ($oldUrl === $newUrl) $errors[] = 'The old and new URLs are the same.';

    if (empty($errors)) {
        if ($action === 'preview') {
            $previewCounts = countReferences($oldUrl);
            // Pass back form values for the confirmation form
            $_SESSION['domain_migration'] = compact('oldUrl', 'newUrl');
        } elseif ($action === 'migrate') {
            $migrationResult = migrateUrls($oldUrl, $newUrl);
            unset($_SESSION['domain_migration']);
            flash('success', 'Domain migration complete. The site now uses ' . $newUrl);
            redirect(siteUrl('admin/domain'));
        }
    }
} else {
    // Pre-fill with current detected URL vs stored canonical URL
    $storedUrl   = Database::setting('site_url', '');
    $detectedUrl = SITE_URL;
    $oldUrl      = $storedUrl;
    $newUrl      = $detectedUrl !== $storedUrl ? $detectedUrl : '';
}

// Current domain info for display
$detectedUrl = SITE_URL;
$storedUrl   = Database::setting('site_url', '');
$lockedInConfig = defined('SITE_URL') && str_contains(file_get_contents(BASE_PATH . '/config/config.local.php') ?? '', "define('SITE_URL'");

adminLayout('Domain & URL Migration', function() use ($detectedUrl, $storedUrl, $oldUrl, $newUrl, $previewCounts, $errors) {
    $errors ??= [];
?>

<!-- Status cards -->
<div class="stats-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:24px;">
  <div class="stat-card" style="border-left-color: #2e7d32;">
    <div class="num" style="font-size:15px; word-break:break-all;"><?= h($detectedUrl) ?></div>
    <div class="label">Currently Active Domain (auto-detected)</div>
  </div>
  <div class="stat-card" style="border-left-color: var(--brown-lt);">
    <div class="num" style="font-size:15px; word-break:break-all;"><?= h($storedUrl ?: '—') ?></div>
    <div class="label">Canonical Domain (stored in database)</div>
  </div>
</div>

<?php if ($detectedUrl === $storedUrl || !$storedUrl): ?>
  <div class="alert alert-success">
    &#10003; The active domain matches the stored canonical domain. Everything looks correct.
  </div>
<?php else: ?>
  <div class="alert alert-warn">
    &#9888; The active domain (<strong><?= h($detectedUrl) ?></strong>) differs from the stored
    canonical domain (<strong><?= h($storedUrl) ?></strong>).
    If you have moved the site to a new domain, run the migration below.
  </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:8px;">

  <!-- Migration form -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">&#128260; Migrate to a New Domain</h2>
    </div>

    <p style="font-family:sans-serif; font-size:13.5px; color:var(--slate-lt); margin-bottom:16px; line-height:1.7;">
      This tool replaces every occurrence of the old domain URL inside your
      page content, blog posts, and media paths — then locks the
      site to the new domain. Run it once when you are ready to go live.
    </p>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error"><?= h($e) ?></div>
    <?php endforeach; ?>

    <?php if ($previewCounts !== null): ?>
      <!-- Step 2: Confirmation after preview -->
      <div style="background:var(--cream); border-radius:var(--radius); padding:14px 16px; margin-bottom:16px;">
        <strong style="display:block; margin-bottom:8px; color:var(--brown-dark);">
          Content that will be updated:
        </strong>
        <table style="width:100%; font-family:sans-serif; font-size:13.5px; line-height:2;">
          <tr><td>Pages with embedded URLs</td><td><strong><?= $previewCounts['pages'] ?></strong></td></tr>
          <tr><td>Blog posts with embedded URLs</td><td><strong><?= $previewCounts['posts'] ?></strong></td></tr>
        </table>
      </div>
      <div class="alert alert-warn" style="font-size:13px;">
        &#9888; This cannot be undone. Back up your database first.
      </div>
      <div style="display:flex; gap:8px;">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="migrate">
          <input type="hidden" name="old_url" value="<?= h($_SESSION['domain_migration']['oldUrl'] ?? '') ?>">
          <input type="hidden" name="new_url" value="<?= h($_SESSION['domain_migration']['newUrl'] ?? '') ?>">
          <button type="submit" class="btn btn-success"
                  onclick="return confirm('Run the domain migration now?');">
            &#10003; Confirm &amp; Migrate
          </button>
        </form>
        <a href="<?= siteUrl('admin/domain') ?>" class="btn btn-secondary">Cancel</a>
      </div>

    <?php else: ?>
      <!-- Step 1: Enter URLs -->
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="preview">
        <div class="form-group">
          <label>Current (old) URL</label>
          <input type="url" name="old_url" class="form-control"
                 value="<?= h($oldUrl ?: $storedUrl) ?>"
                 placeholder="https://your-old-domain.org" required>
          <div class="form-hint">The domain the site is moving <em>from</em>.</div>
        </div>
        <div class="form-group">
          <label>New URL</label>
          <input type="url" name="new_url" class="form-control"
                 value="<?= h($newUrl) ?>"
                 placeholder="https://your-site.org" required>
          <div class="form-hint">The domain the site is moving <em>to</em>.</div>
        </div>
        <button type="submit" class="btn btn-primary">Preview Changes &rarr;</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- How it works -->
  <div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">&#128161; How domain switching works</h2></div>
      <div style="font-family:sans-serif; font-size:13.5px; line-height:1.9; color:var(--slate-lt);">
        <p style="margin-bottom:10px;">
          <strong style="color:var(--slate);">No configuration needed to test on a new domain.</strong>
          The CMS automatically detects whatever domain it is being served on.
          Simply point <code>your-old-domain.org</code> to your server and
          it works immediately — no file edits required.
        </p>
        <p style="margin-bottom:10px;">
          <strong style="color:var(--slate);">When you are ready to go live on your-site.org:</strong>
          Run the migration tool on the left. It will:
        </p>
        <ol style="margin-left:18px; margin-bottom:10px; line-height:2;">
          <li>Search every page and post for the old URL</li>
          <li>Replace it with the new URL</li>
          <li>Update media library paths</li>
          <li>Lock <code>config.local.php</code> to the new domain</li>
        </ol>
        <p style="margin-bottom:10px;">
          <strong style="color:var(--slate);">After migrating:</strong>
          Point your DNS for <code>your-site.org</code> to the server.
          Both domains will still work until you remove the old one.
        </p>
        <p style="margin-bottom:10px;">
          <strong style="color:var(--slate);">SSL / HTTPS:</strong>
          Run Certbot on the server for each domain:
          <code>sudo certbot --apache -d your-site.org</code>
        </p>
        <?php if (defined('NETWORK_MODE') && NETWORK_MODE): ?>
        <p style="background:#fff3e0; padding:8px 10px; border-radius:4px; color:#e65100; font-size:12px; margin-top:10px;">
          &#9888; <strong>Network mode:</strong> SITE_URL is never locked in config — each subsite
          auto-detects its own domain from the incoming request. The migration updates domain
          references in content and the <code>site_url</code> setting for every subsite automatically.
        </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">&#128196; Lock domain in config</h2></div>
      <div style="font-family:sans-serif; font-size:13.5px; line-height:1.8; color:var(--slate-lt);">
        <p style="margin-bottom:10px;">
          After migration the domain is locked into <code>config/config.local.php</code>
          automatically, so the site will always use that URL even if accessed by IP address or
          another hostname.
        </p>
        <p>
          To un-lock it (go back to auto-detection), edit
          <code>config/config.local.php</code> and comment out or remove the
          <code>define('SITE_URL', ...)</code> line.
        </p>
      </div>
    </div>
  </div>

</div>

<?php }); ?>
