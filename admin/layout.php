<?php
// Lazy-load Updater if available (used for sidebar badge)
if (!class_exists('Updater') && defined('BASE_PATH')) {
    @require_once BASE_PATH . '/core/Updater.php';
}

// Admin layout helper — call adminLayout('Page Title', fn() => { ... render body ... })
function adminLayout(string $pageTitle, callable $body): void {
    $user     = Auth::user();
    $siteName = setting('site_name', 'Your Parish');
    $csrf     = Auth::csrf();

    $isNetworkMode = defined('NETWORK_MODE') && NETWORK_MODE;
    $isSuperAdmin  = Auth::isSuperAdmin();

    // In network mode, determine which site we're administering.
    // Super admins on the main domain see the network panel by default.
    $currentSiteId  = Database::siteId();
    $currentSiteRow = null;
    if ($isNetworkMode) {
        $currentSiteRow = Database::fetch("SELECT * FROM network_sites WHERE id = ?", [$currentSiteId]);
    }

    $req = trim(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), '/');

    // Build nav items — each has [label, href, icon, requirePerm (optional)]
    $nav = [
        ['Dashboard',        'admin/',                '&#9632;',  null],
        ['Users',            'admin/users',           '&#128100;','manage_users'],
        ['Pages',            'admin/pages',           '&#128196;','manage_content'],
        ['Blog Posts',       'admin/posts',           '&#9998;',  'manage_content'],
        ['Categories',       'admin/categories',      '&#9741;',  'manage_content'],
        ['Tags',             'admin/tags',            '&#9872;',  'manage_content'],
        ['Media',            'admin/media',           '&#128247;','manage_media'],
        ['Analytics',        'admin/analytics',       '&#128200;','view_analytics'],
        ['Contact',          'admin/contacts',        '&#9993;',  'manage_contacts'],
        ['Prayer Requests',  'admin/prayers',         '&#9827;',  'manage_prayers'],
        ['Contact & Prayer Pages', 'admin/contact-prayer', '&#9998;', 'manage_contacts'],
        ['Forms',            'admin/forms',           '&#128196;','manage_contacts'],
        ['Settings',         'admin/settings',        '&#9881;',  'manage_settings'],
        ['Domain / URL',     'admin/domain',          '&#127760;','manage_settings'],
        ['Updates',          'admin/updates',         '&#128260;','manage_settings', true],
    ];

    // Pending migrations badge
    $pendingMigrations = 0;
    if (Auth::can('admin') && class_exists('Updater')) {
        try { $pendingMigrations = count(Updater::pendingMigrations()); } catch (\Throwable) {}
    }

    // Admin CSP — Report-Only while Chart.js CDN is in use.
    // Jodit is served locally so no extra CDN domains are needed.
    // Switch to Content-Security-Policy once reports are clean.
    $adminNonce = cspNonce();
    $adminCsp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$adminNonce}' 'unsafe-inline' https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "font-src 'self'",
        "frame-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
    if (!headers_sent()) {
        header("Content-Security-Policy-Report-Only: {$adminCsp}");
    }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — <?= h($siteName) ?> Admin</title>
<link rel="stylesheet" href="<?= siteUrl('public/assets/css/admin.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script src="<?= siteUrl('public/assets/js/admin.js') ?>" defer></script>
</head>
<body>
<div class="admin-wrap">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <h1><?= h($siteName) ?><br><small>Admin Panel</small></h1>
    </div>
    <nav class="sidebar-nav">
      <div class="sidebar-section">Content</div>
      <?php foreach ($nav as $navItem):
        [$label, $href, $icon, $perm] = array_pad($navItem, 4, null);
        $isUpdates = ($href === 'admin/updates');

        // Skip items the user doesn't have permission to see
        if ($perm && !Auth::hasPermission($perm)) continue;

        $active = ($href === 'admin/')
            ? ($req === 'admin' || $req === 'admin/')
            : str_starts_with($req, trim($href, '/'));
      ?>
        <a href="<?= siteUrl($href) ?>" class="<?= $active ? 'active' : '' ?>"
           style="justify-content:space-between;">
          <span style="display:flex;align-items:center;gap:10px;">
            <span class="icon"><?= $icon ?></span>
            <?= h($label) ?>
          </span>
          <?php if ($isUpdates && $pendingMigrations > 0): ?>
            <span style="background:#e65100;color:#fff;border-radius:10px;font-size:10px;padding:1px 6px;font-family:sans-serif;font-weight:700;">
              <?= $pendingMigrations ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php if (Auth::hasPermission('manage_content') && Database::siteId() === 1): ?>
      <a href="<?= siteUrl('admin/parish-locator') ?>" class="<?= str_starts_with($req, 'admin/parish-locator') ? 'active' : '' ?>">
        <span class="icon">&#9783;</span> Parish Locator
      </a>
      <a href="<?= siteUrl('admin/clergy') ?>" class="<?= str_starts_with($req, 'admin/clergy') ? 'active' : '' ?>">
        <span class="icon">&#9827;</span> Clergy Directory
      </a>
      <a href="<?= siteUrl('admin/daily-readings') ?>" class="<?= str_starts_with($req, 'admin/daily-readings') ? 'active' : '' ?>">
        <span class="icon">&#9998;</span> Daily Readings
      </a>
      <?php endif; ?>

      <?php if (Auth::hasPermission('view_records') && Database::siteId() === 1): ?>
      <div class="sidebar-section">Records</div>
      <a href="<?= siteUrl('admin/records/') ?>" class="<?= ($req === 'admin/records' || $req === 'admin/records/') ? 'active' : '' ?>">
        <span class="icon">&#9827;</span> All Registers
      </a>
      <a href="<?= siteUrl('admin/records/baptisms') ?>" class="<?= str_starts_with($req, 'admin/records/baptisms') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> Baptisms
      </a>
      <a href="<?= siteUrl('admin/records/confirmations') ?>" class="<?= str_starts_with($req, 'admin/records/confirmations') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> Confirmations
      </a>
      <a href="<?= siteUrl('admin/records/communions') ?>" class="<?= str_starts_with($req, 'admin/records/communions') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> First Communion
      </a>
      <a href="<?= siteUrl('admin/records/marriages') ?>" class="<?= str_starts_with($req, 'admin/records/marriages') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> Marriages
      </a>
      <a href="<?= siteUrl('admin/records/deaths') ?>" class="<?= str_starts_with($req, 'admin/records/deaths') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> Deaths
      </a>
      <a href="<?= siteUrl('admin/records/ordinations') ?>" class="<?= str_starts_with($req, 'admin/records/ordinations') ? 'active' : '' ?>">
        <span class="icon">&#43;</span> Ordinations
      </a>
      <a href="<?= siteUrl('admin/records/report') ?>" class="<?= str_starts_with($req, 'admin/records/report') ? 'active' : '' ?>">
        <span class="icon">&#128269;</span> Person Report
      </a>
      <a href="<?= siteUrl('admin/records/parishes') ?>" class="<?= str_starts_with($req, 'admin/records/parishes') ? 'active' : '' ?>">
        <span class="icon">&#9783;</span> Parishes
      </a>
      <?php if (Auth::hasPermission('manage_records_settings')): ?>
      <a href="<?= siteUrl('admin/records/settings') ?>" class="<?= str_starts_with($req, 'admin/records/settings') ? 'active' : '' ?>">
        <span class="icon">&#9881;</span> NSR Settings
      </a>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($isNetworkMode && $isSuperAdmin): ?>
      <div class="sidebar-section">Network</div>
      <a href="<?= networkUrl('admin/network/') ?>"
         class="<?= str_starts_with($req, 'admin/network') ? 'active' : '' ?>">
        <span class="icon">&#127760;</span> Network Dashboard
      </a>
      <a href="<?= networkUrl('admin/network/sites') ?>"
         class="<?= $req === 'admin/network/sites' ? 'active' : '' ?>">
        <span class="icon">&#9783;</span> Manage Sites
      </a>
      <?php endif; ?>

      <div class="sidebar-section">Site</div>
      <a href="<?= siteUrl() ?>" target="_blank">
        <span class="icon">&#127760;</span> View Site
      </a>
    </nav>
    <div class="sidebar-footer">
      Signed in as <strong><?= h($user['name']) ?></strong>
      <span style="font-size:11px; opacity:.7; display:block; margin-top:2px;"><?= h(ucfirst(str_replace('_', ' ', $user['role']))) ?></span>
      <?php if ($isNetworkMode && $currentSiteRow): ?>
      <span style="font-size:10px; opacity:.6; display:block; margin-top:2px;">
        Site: <?= h($currentSiteRow['name']) ?>
      </span>
      <?php endif; ?>
      <a href="<?= siteUrl('admin/logout') ?>">Log out</a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-title"><?= h($pageTitle) ?></div>
      <div class="topbar-right">
        <span>&#9786; <?= h($user['name']) ?></span>
        <a href="<?= siteUrl() ?>" target="_blank">&#127760; View Site</a>
        <a href="<?= siteUrl('admin/logout') ?>">Log out</a>
      </div>
    </header>

    <div class="content">
      <?php if ($isNetworkMode && $currentSiteRow && $isSuperAdmin): ?>
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
                  background:#2c3e50; color:#ecf0f1; padding:8px 16px; border-radius:6px; margin-bottom:16px; font-size:13px;">
        <span>
          &#9783; <strong>Network mode</strong> &mdash;
          Managing:
          <strong><?= h($currentSiteRow['name']) ?></strong>
          <?php if ($currentSiteRow['subdomain'] !== ''): ?>
            (<code style="font-size:11px; opacity:.8;"><?= h($currentSiteRow['subdomain']) ?>.<?= h(NETWORK_BASE_DOMAIN) ?></code>)
          <?php else: ?>
            (main domain)
          <?php endif; ?>
        </span>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a href="<?= networkUrl('admin/network/') ?>"
             style="color:#3498db; text-decoration:none; font-size:12px;">&#8592; Network Dashboard</a>
          <?php if ($currentSiteRow['subdomain'] !== ''): ?>
          <a href="<?= h(subsiteUrl($currentSiteRow['subdomain'], 'admin/')) ?>"
             style="color:#3498db; text-decoration:none; font-size:12px;" target="_blank">
            Site Admin &#8599;
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php foreach (['success','error','info','warn'] as $type):
        $msg = flash($type); if (!$msg) continue; ?>
        <div class="alert alert-<?= $type ?>"><?= h($msg) ?></div>
      <?php endforeach; ?>

      <?php $body(); ?>
    </div>
  </div>

</div>
</body>
</html>
<?php } ?>
