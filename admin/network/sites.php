<?php
// Network Admin — Sites (list, create, suspend, delete)
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireSuperAdmin();

$errors = [];
$editSite = null;
$editId   = (int) ($_GET['edit'] ?? 0);

if ($editId) {
    $editSite = Database::fetch("SELECT * FROM network_sites WHERE id = ?", [$editId]);
    if (!$editSite) { http_response_code(404); die('Site not found.'); }
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create new site
    if ($action === 'create') {
        $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));
        $name      = trim($_POST['name'] ?? '');

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,49}$/', $subdomain)) {
            $errors[] = 'Subdomain must be 1–50 lowercase letters, numbers, or hyphens, and start with a letter or number.';
        } elseif (in_array($subdomain, ['www', 'mail', 'ftp', 'admin', 'api', 'network'], true)) {
            $errors[] = "The subdomain '$subdomain' is reserved.";
        } elseif (Database::fetch("SELECT id FROM network_sites WHERE subdomain = ?", [$subdomain])) {
            $errors[] = "Subdomain '$subdomain' is already taken.";
        }
        if (!$name) $errors[] = 'Site name is required.';

        if (!$errors) {
            $newId = Database::insert('network_sites', [
                'subdomain' => $subdomain,
                'name'      => $name,
                'status'    => 'active',
            ]);

            // Seed default settings for the new site
            $seedSettings = [
                'site_name'            => $name,
                'site_tagline'         => 'A Community of Faith',
                'site_url'             => 'https://' . $subdomain . '.' . NETWORK_BASE_DOMAIN,
                'admin_email'          => '',
                'posts_per_page'       => '10',
                'date_format'          => 'F j, Y',
                'timezone'             => 'America/Chicago',
                'analytics_enabled'    => '1',
                'analytics_exclude_admins' => '1',
            ];
            foreach ($seedSettings as $k => $v) {
                Database::query(
                    "INSERT IGNORE INTO settings (site_id, `key`, `value`) VALUES (?, ?, ?)",
                    [$newId, $k, $v]
                );
            }

            // Seed a default home page
            Database::insert('pages', [
                'site_id'     => $newId,
                'title'       => 'Home',
                'slug'        => 'home',
                'content'     => '<h2>Welcome to ' . htmlspecialchars($name) . '</h2><p>We are a community of faith. All are welcome here.</p>',
                'status'      => 'published',
                'show_in_nav' => 0,
                'nav_label'   => 'Home',
                'menu_order'  => 0,
                'author_id'   => Auth::id(),
            ]);

            flash('success', "Site '$name' created. DNS: point $subdomain." . NETWORK_BASE_DOMAIN . " to this server.");
            redirect(networkUrl('admin/network/sites'));
        }
    }

    // Update existing site
    if ($action === 'update') {
        $id     = (int) ($_POST['site_id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active','suspended']) ? $_POST['status'] : 'active';

        if (!$name) $errors[] = 'Site name is required.';

        if (!$errors) {
            Database::update('network_sites', ['name' => $name, 'status' => $status], 'id = ?', [$id]);

            // Also update the site_name setting for this site
            Database::query(
                "INSERT INTO settings (site_id, `key`, `value`) VALUES (?, 'site_name', ?)
                 ON DUPLICATE KEY UPDATE `value` = ?",
                [$id, $name, $name]
            );

            flash('success', 'Site updated.');
            redirect(networkUrl('admin/network/sites'));
        }
    }

    // Delete site (super admin only, non-primary)
    if ($action === 'delete') {
        $id = (int) ($_POST['site_id'] ?? 0);
        if ($id <= 1) {
            flash('error', 'The primary site cannot be deleted.');
        } else {
            // Remove all content for this site
            foreach (['pages','posts','settings','contacts',
                      'prayer_requests','media','analytics_views'] as $t) {
                try { Database::delete($t, 'site_id = ?', [$id]); } catch (\Throwable) {}
            }
            Database::delete('network_sites', 'id = ?', [$id]);
            flash('success', 'Site deleted.');
        }
        redirect(networkUrl('admin/network/sites'));
    }
}

$sites = Database::fetchAll("SELECT * FROM network_sites ORDER BY id ASC");

adminLayout('Network Sites', function() use ($sites, $errors, $editSite, $editId) {
?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

  <!-- Site list -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">All Sites</h2>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>ID</th><th>Name</th><th>Subdomain</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($sites as $site): ?>
          <tr>
            <td><?= $site['id'] ?></td>
            <td><strong><?= h($site['name']) ?></strong></td>
            <td>
              <?php if ($site['subdomain'] === ''): ?>
                <em style="color:#999;">main domain</em>
              <?php else: ?>
                <a href="<?= h(subsiteUrl($site['subdomain'])) ?>" target="_blank" style="font-family:monospace;">
                  <?= h($site['subdomain']) ?>.<?= h(NETWORK_BASE_DOMAIN) ?> &#8599;
                </a>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $site['status'] === 'active' ? 'published' : 'draft' ?>">
                <?= $site['status'] ?>
              </span>
            </td>
            <td style="display:flex; gap:6px; flex-wrap:wrap;">
              <a href="?edit=<?= $site['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
              <a href="<?= subsiteUrl($site['subdomain'], 'admin/') ?>"
                 class="btn btn-sm btn-secondary" target="_blank">Admin &#8599;</a>
              <?php if ($site['id'] > 1): ?>
              <form method="post" onsubmit="return confirm('Delete this site and ALL its content?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#c0392b; color:#fff;">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Create / Edit form -->
  <div>
    <?php if ($editSite): ?>
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Edit Site</h2>
        <a href="<?= networkUrl('admin/network/sites') ?>" class="btn btn-sm btn-secondary">Cancel</a>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="site_id" value="<?= $editSite['id'] ?>">
        <div class="form-group">
          <label>Site Name</label>
          <input type="text" name="name" class="form-control"
                 value="<?= h($_POST['name'] ?? $editSite['name']) ?>" required>
        </div>
        <?php if ($editSite['subdomain'] !== ''): ?>
        <div class="form-group">
          <label>Subdomain</label>
          <input type="text" class="form-control" value="<?= h($editSite['subdomain']) ?>" disabled>
          <div class="form-hint">Subdomains cannot be changed after creation.</div>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Status</label>
          <select name="status" class="form-control">
            <option value="active" <?= ($editSite['status']==='active') ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= ($editSite['status']==='suspended') ? 'selected' : '' ?>>Suspended</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Add New Site</h2>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group">
          <label>Subdomain</label>
          <div style="display:flex; align-items:center; gap:6px;">
            <input type="text" name="subdomain" class="form-control"
                   value="<?= h($_POST['subdomain'] ?? '') ?>"
                   placeholder="osfoc" pattern="[a-z0-9][a-z0-9\-]*" required
                   style="max-width:140px;">
            <span style="color:#666; white-space:nowrap;">.<?= h(NETWORK_BASE_DOMAIN) ?></span>
          </div>
          <div class="form-hint">Lowercase letters, numbers, hyphens only. Cannot be changed later.</div>
        </div>
        <div class="form-group">
          <label>Site Name</label>
          <input type="text" name="name" class="form-control"
                 value="<?= h($_POST['name'] ?? '') ?>"
                 placeholder="e.g. Saint Kolbe Parish" required>
        </div>
        <button type="submit" class="btn btn-primary">Create Site</button>
      </form>

      <div style="margin-top:20px; padding:12px 14px; background:#fdf6ec; border:1px solid #e8d9c4; border-radius:6px; font-size:12px; color:#555; line-height:1.6;">
        <strong>After creating a site:</strong>
        <ol style="margin:6px 0 0 16px; padding:0;">
          <li>Add a wildcard DNS record <code>*.<?= h(NETWORK_BASE_DOMAIN) ?></code> pointing to this server (if not already set).</li>
          <li>Add the subdomain to your SSL certificate (wildcard cert recommended).</li>
          <li>Visit the site's admin panel to configure its settings.</li>
        </ol>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php }); ?>
