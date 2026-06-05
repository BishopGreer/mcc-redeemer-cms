<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_roles');

$siteId    = Database::siteId();
$editId    = (int)($_GET['id'] ?? 0);
$action    = $_GET['action'] ?? '';

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $postAction = $_POST['_action'] ?? 'save';

    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            // Remove role assignments from users first
            Database::query("UPDATE users SET custom_role_id = NULL WHERE custom_role_id = ?", [$delId]);
            Database::query("DELETE FROM custom_role_permissions WHERE role_id = ?", [$delId]);
            Database::query("DELETE FROM custom_roles WHERE id = ? AND site_id = ?", [$delId, $siteId]);
            flash('success', 'Role deleted.');
        }
        redirect(siteUrl('admin/roles'));
    }

    // Save (create or update)
    $saveId   = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $slug     = trim($_POST['slug'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $baseRole = in_array($_POST['base_role'] ?? '', ['parishioner','author','editor','admin'])
                ? $_POST['base_role'] : 'editor';
    $perms    = array_keys($_POST['perm'] ?? []);

    if (!$name) { flash('error', 'Role name is required.'); redirect(siteUrl('admin/roles')); }

    if (!$slug) {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name));
        $slug = trim($slug, '-');
    }
    // Ensure slug uniqueness
    $clash = Database::fetch(
        "SELECT id FROM custom_roles WHERE slug = ? AND site_id = ? AND id != ?",
        [$slug, $siteId, $saveId]
    );
    if ($clash) {
        $slug .= '-' . ($saveId ?: time());
    }

    if ($saveId) {
        $existing = Database::fetch("SELECT id FROM custom_roles WHERE id = ? AND site_id = ?", [$saveId, $siteId]);
        if ($existing) {
            Database::update('custom_roles', [
                'name' => $name, 'slug' => $slug, 'description' => $desc, 'base_role' => $baseRole,
            ], 'id = ? AND site_id = ?', [$saveId, $siteId]);
            Database::query("DELETE FROM custom_role_permissions WHERE role_id = ?", [$saveId]);
            foreach ($perms as $p) {
                Database::insert('custom_role_permissions', ['role_id' => $saveId, 'permission' => $p]);
            }
            flash('success', 'Role updated.');
        }
    } else {
        $newId = Database::insert('custom_roles', [
            'site_id' => $siteId, 'name' => $name, 'slug' => $slug,
            'description' => $desc, 'base_role' => $baseRole,
        ]);
        foreach ($perms as $p) {
            Database::insert('custom_role_permissions', ['role_id' => $newId, 'permission' => $p]);
        }
        flash('success', 'Role created.');
    }
    redirect(siteUrl('admin/roles'));
}

// ── Load data ─────────────────────────────────────────────────
$roles = Database::fetchAll(
    "SELECT r.*, COUNT(crp.permission) AS perm_count,
            (SELECT COUNT(*) FROM users u WHERE u.custom_role_id = r.id) AS user_count
     FROM custom_roles r
     LEFT JOIN custom_role_permissions crp ON crp.role_id = r.id
     WHERE r.site_id = ?
     GROUP BY r.id
     ORDER BY r.name",
    [$siteId]
);

$editRole  = $editId ? Database::fetch("SELECT * FROM custom_roles WHERE id = ? AND site_id = ?", [$editId, $siteId]) : null;
$editPerms = $editId ? array_column(
    Database::fetchAll("SELECT permission FROM custom_role_permissions WHERE role_id = ?", [$editId]),
    'permission'
) : [];

$showForm = $editRole || $action === 'new';

// All available permissions, grouped
$permGroups = [
    'Administration' => [
        'manage_users'   => 'Manage Users',
        'manage_roles'   => 'Manage Roles',
        'manage_settings'=> 'Manage Site Settings',
    ],
    'Content' => [
        'manage_content' => 'Pages & Blog Posts',
        'manage_media'   => 'Media Library',
        'view_analytics' => 'View Analytics',
    ],
    'Events' => [
        'manage_events'  => 'Manage Events Calendar',
    ],
    'Forms & Contacts' => [
        'manage_contacts'=> 'Contact Form Submissions',
    ],
];

$baseRoleLabels = [
    'admin'       => 'Admin — full admin panel navigation',
    'editor'      => 'Editor — content editing access',
    'author'      => 'Author — post/page authoring',
    'parishioner' => 'Parishioner — no admin access by default',
];

adminLayout('Roles', function() use ($roles, $editRole, $editPerms, $showForm, $action, $editId, $permGroups, $baseRoleLabels) {
?>
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
  <div>
    <h1 class="page-title" style="margin:0;">Custom Roles</h1>
    <p style="margin:4px 0 0; color:#6b7280;">Define roles with specific permissions and assign them to users.</p>
  </div>
  <?php if (!$showForm): ?>
  <a href="<?= siteUrl('admin/roles?action=new') ?>" class="btn btn-primary">+ New Role</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<?php
$fr     = $editRole ?? [];
$frId   = (int)($fr['id'] ?? 0);
$frBase = $fr['base_role'] ?? 'editor';
?>
<div class="card" style="margin-bottom:28px;">
  <div class="card-header">
    <h2 class="card-title"><?= $frId ? 'Edit Role: ' . h($fr['name']) : 'New Role' ?></h2>
  </div>
  <form method="POST" style="padding:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $frId ?>">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">

      <!-- Left: role info -->
      <div>
        <div class="form-group">
          <label>Role Name <span style="color:#e65100;">*</span></label>
          <input type="text" name="name" class="form-control" required
                 value="<?= h($fr['name'] ?? '') ?>" placeholder="e.g. Event Coordinator">
        </div>
        <div class="form-group">
          <label>Slug</label>
          <input type="text" name="slug" class="form-control"
                 value="<?= h($fr['slug'] ?? '') ?>" placeholder="auto-generated">
          <div class="form-hint">Used internally. Leave blank to auto-generate.</div>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="What is this role for?"><?= h($fr['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label>Base Role Level</label>
          <select name="base_role" class="form-control">
            <?php foreach ($baseRoleLabels as $k => $v): ?>
            <option value="<?= $k ?>" <?= $frBase === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">
            Controls which admin sections this role can navigate to.
            Permissions below override or extend what the base role grants.
          </div>
        </div>
      </div>

      <!-- Right: permissions -->
      <div>
        <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:10px;">
          Permissions
        </div>
        <?php foreach ($permGroups as $groupLabel => $groupPerms): ?>
        <div style="margin-bottom:16px;">
          <div style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6B3FA0;
                      font-weight:700; margin-bottom:6px; border-bottom:1px solid #ede9fe; padding-bottom:3px;">
            <?= h($groupLabel) ?>
          </div>
          <?php foreach ($groupPerms as $permKey => $permLabel): ?>
          <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer; font-size:13px;">
            <input type="checkbox" name="perm[<?= $permKey ?>]" value="1"
                   <?= in_array($permKey, $editPerms) ? 'checked' : '' ?>>
            <?= h($permLabel) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>

    </div>

    <div style="display:flex; gap:10px; border-top:1px solid #e5e7eb; padding-top:16px;">
      <button type="submit" class="btn btn-primary"><?= $frId ? 'Save Changes' : 'Create Role' ?></button>
      <a href="<?= siteUrl('admin/roles') ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Roles list -->
<?php if (empty($roles) && !$showForm): ?>
<div class="card" style="text-align:center; padding:48px 24px;">
  <p style="color:#6b7280;">No custom roles yet. Create one to assign fine-grained permissions.</p>
  <a href="<?= siteUrl('admin/roles?action=new') ?>" class="btn btn-primary" style="margin-top:16px;">Create First Role</a>
</div>
<?php elseif (!empty($roles)): ?>
<div class="card" style="padding:0; overflow:hidden;">
  <table class="data-table" style="margin:0;">
    <thead>
      <tr>
        <th>Role</th>
        <th>Base Level</th>
        <th>Permissions</th>
        <th>Users</th>
        <th style="width:130px;"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
    <tr>
      <td>
        <strong><?= h($r['name']) ?></strong>
        <?php if ($r['description']): ?>
        <div style="font-size:0.8rem; color:#9ca3af;"><?= h($r['description']) ?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:0.85rem;">
        <span style="background:#ede9fe; color:#6B3FA0; padding:2px 8px; border-radius:10px; font-size:0.78rem; font-weight:600;">
          <?= ucfirst($r['base_role']) ?>
        </span>
      </td>
      <td style="font-size:0.85rem; color:#6b7280;"><?= (int)$r['perm_count'] ?> permission<?= $r['perm_count'] != 1 ? 's' : '' ?></td>
      <td style="font-size:0.85rem; color:#6b7280;"><?= (int)$r['user_count'] ?> user<?= $r['user_count'] != 1 ? 's' : '' ?></td>
      <td style="white-space:nowrap;">
        <a href="<?= siteUrl('admin/roles?id=' . $r['id']) ?>" class="btn btn-sm btn-secondary">Edit</a>
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('Delete role &quot;<?= h(addslashes($r['name'])) ?>&quot;? Users assigned this role will revert to their base role.')">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-sm btn-danger">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
});
