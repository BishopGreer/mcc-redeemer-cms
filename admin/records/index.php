<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

// Check that NSR tables exist
$tablesOk = Database::fetch("SHOW TABLES LIKE 'occi_baptisms'");

if (!$tablesOk) {
    adminLayout('Sacramental Records', function() { ?>
      <div class="alert alert-error">
        The NSR tables are missing. Please go to
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>
        and run pending migrations, then return here.
      </div>
    <?php });
    return;
}

// Gather counts
$counts = [
    'baptisms'      => Database::fetch("SELECT COUNT(*) AS n FROM occi_baptisms")['n']      ?? 0,
    'confirmations' => Database::fetch("SELECT COUNT(*) AS n FROM occi_confirmations")['n'] ?? 0,
    'communions'    => Database::fetch("SELECT COUNT(*) AS n FROM occi_communions")['n']    ?? 0,
    'marriages'     => Database::fetch("SELECT COUNT(*) AS n FROM occi_marriages")['n']     ?? 0,
    'deaths'        => Database::fetch("SELECT COUNT(*) AS n FROM occi_deaths")['n']        ?? 0,
    'ordinations'   => Database::fetch("SELECT COUNT(*) AS n FROM occi_ordinations")['n']   ?? 0,
];

$registers = [
    ['Baptisms',      'baptisms',      '&#9827;', $counts['baptisms']],
    ['Confirmations', 'confirmations', '&#9827;', $counts['confirmations']],
    ['First Communions','communions',  '&#9827;', $counts['communions']],
    ['Marriages',     'marriages',     '&#9827;', $counts['marriages']],
    ['Deaths',        'deaths',        '&#9827;', $counts['deaths']],
    ['Ordinations',   'ordinations',   '&#9827;', $counts['ordinations']],
];

adminLayout('Sacramental Records', function() use ($registers, $counts) {
?>
<div style="margin-bottom:24px;">
  <h2 style="margin:0 0 6px; font-size:20px;">OCCI National Sacramental Records</h2>
  <p style="margin:0; color:#666; font-size:14px;">
    All records in this system are canonical documents of the Old Catholic Church International.
  </p>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:16px; margin-bottom:32px;">
  <?php foreach ($registers as [$label, $slug, $icon, $count]): ?>
  <div class="card" style="padding:20px; text-align:center;">
    <div style="font-size:32px; margin-bottom:8px;"><?= $icon ?></div>
    <div style="font-size:28px; font-weight:700; color:#2c3e50;"><?= (int)$count ?></div>
    <div style="font-size:13px; color:#666; margin-bottom:16px;"><?= h($label) ?></div>
    <a href="<?= siteUrl('admin/records/' . $slug) ?>" class="btn btn-primary btn-sm" style="display:block;">View Register</a>
    <?php if (Auth::hasPermission('edit_records')): ?>
    <a href="<?= siteUrl('admin/records/' . $slug . '/new') ?>" class="btn btn-secondary btn-sm" style="display:block; margin-top:6px;">+ Add Record</a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
  <a href="<?= siteUrl('admin/records/report') ?>" class="btn btn-secondary">&#128269; Person Sacramental Report</a>
  <a href="<?= siteUrl('admin/records/parishes') ?>" class="btn btn-secondary">&#9783; Manage Parishes</a>
  <?php if (Auth::hasPermission('manage_records_settings')): ?>
  <a href="<?= siteUrl('admin/records/settings') ?>" class="btn btn-secondary">&#9881; NSR Settings</a>
  <?php endif; ?>
</div>

<?php
});
