<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_records_settings');

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $settingsToSave = [
        'nsr_default_minister' => trim($_POST['nsr_default_minister'] ?? ''),
        'nsr_cert_header'      => trim($_POST['nsr_cert_header']      ?? ''),
    ];

    foreach ($settingsToSave as $key => $value) {
        Database::query(
            "INSERT INTO settings (`key`, `value`, `site_id`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$key, $value, Database::siteId()]
        );
    }

    flash('success', 'NSR settings saved.');
    redirect(siteUrl('admin/records/settings'));
}

// Load current settings
$settingValues = [];
$rows = Database::fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'nsr_%' AND site_id = ?", [Database::siteId()]);
foreach ($rows as $row) {
    $settingValues[$row['key']] = $row['value'];
}

adminLayout('NSR Settings', function() use ($settingValues) {
?>
<div style="max-width:640px;">
  <p style="font-size:14px; color:#666; margin-bottom:24px;">
    These settings apply to all National Sacramental Records forms and certificates on this site.
  </p>

  <form method="post" action="<?= siteUrl('admin/records/settings') ?>">
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h3 class="card-title" style="font-size:15px;">Form Defaults</h3></div>
      <div style="display:grid; gap:14px; padding:4px 0;">
        <div>
          <label style="display:block; font-size:13px; margin-bottom:4px;">Default Minister Name</label>
          <input type="text" name="nsr_default_minister"
                 value="<?= h($settingValues['nsr_default_minister'] ?? '') ?>"
                 class="form-control" placeholder="e.g. Rev. Jane Smith">
          <p style="font-size:12px; color:#888; margin:4px 0 0;">Pre-filled in the minister field on baptism and communion forms.</p>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><h3 class="card-title" style="font-size:15px;">Certificate Appearance</h3></div>
      <div style="display:grid; gap:14px; padding:4px 0;">
        <div>
          <label style="display:block; font-size:13px; margin-bottom:4px;">Certificate Header Text</label>
          <input type="text" name="nsr_cert_header"
                 value="<?= h($settingValues['nsr_cert_header'] ?? '') ?>"
                 class="form-control" placeholder="e.g. Diocese of the Southwest">
          <p style="font-size:12px; color:#888; margin:4px 0 0;">Appears under the OCCI name on printed certificates. Leave blank for the default "Office of Canonical Records".</p>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>
<?php });
