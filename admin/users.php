<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_users');

$currentUserId = Auth::id();
$editId        = (int)($_GET['id'] ?? 0);
$editUser      = $editId ? Database::fetch("SELECT * FROM users WHERE id = ? AND (site_id = ? OR site_id IS NULL)", [$editId, Database::siteId()]) : null;
$isSuperAdmin  = Auth::isSuperAdmin();
$allSites      = $isSuperAdmin ? Database::fetchAll("SELECT id, name, subdomain FROM network_sites WHERE status='active' ORDER BY id") : [];

// -------------------------------------------------------
// POST handlers
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $action = $_POST['_action'] ?? 'save';

    // Delete
    if ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId && $delId !== $currentUserId) {
            Database::query("DELETE FROM users WHERE id = ? AND (site_id = ? OR site_id IS NULL)", [$delId, Database::siteId()]);
            flash('success', 'User deleted.');
        } else {
            flash('error', 'You cannot delete your own account.');
        }
        redirect(siteUrl('admin/users'));
    }

    // Save (create or update)
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role  = $_POST['role'] ?? 'author';
    $uid   = (int)($_POST['id'] ?? 0);

    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Name and a valid email address are required.');
        redirect(siteUrl($uid ? "admin/users?id={$uid}" : 'admin/users?action=new'));
    }

    if (!in_array($role, ['admin', 'editor', 'author', 'parishioner'])) {
        $role = 'author';
    }

    // Build permissions JSON from checkboxes
    $permOverrides = [];
    foreach (array_keys(Auth::PERMISSIONS) as $perm) {
        $minRole  = Auth::PERMISSIONS[$perm]['default_role'];
        $minLevel = Auth::ROLE_HIERARCHY[$minRole] ?? 99;
        $roleLevel = Auth::ROLE_HIERARCHY[$role] ?? 0;
        $roleDefault = $roleLevel >= $minLevel;

        $checked = isset($_POST['perm'][$perm]);

        // Only store an override if it differs from the role default
        if ($checked !== $roleDefault) {
            $permOverrides[$perm] = $checked;
        }
    }
    $permJson = empty($permOverrides) ? null : json_encode($permOverrides);

    if ($uid) {
        // Update existing user
        // Prevent lowering your own role
        if ($uid === $currentUserId && $role !== Auth::role()) {
            flash('error', 'You cannot change your own role.');
            redirect(siteUrl("admin/users?id={$uid}"));
        }

        $data = ['name' => $name, 'email' => $email, 'permissions' => $permJson];
        if ($uid !== $currentUserId) {
            $data['role'] = $role;
            // Site assignment (super_admin only, cannot change own site)
            if (Auth::isSuperAdmin()) {
                $sitePost = $_POST['user_site_id'] ?? 'current';
                if ($sitePost === 'network') {
                    $data['site_id'] = null;
                } elseif (is_numeric($sitePost)) {
                    $data['site_id'] = (int)$sitePost;
                }
            }
        }
        if (!empty($_POST['password'])) {
            $data['password'] = Auth::hashPassword($_POST['password']);
        }

        // Check email uniqueness
        $clash = Database::fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $uid]);
        if ($clash) {
            flash('error', 'That email address is already in use.');
            redirect(siteUrl("admin/users?id={$uid}"));
        }

        Database::update('users', $data, 'id = ?', [$uid]);
        flash('success', 'User updated.');
        redirect(siteUrl('admin/users'));
    } else {
        // Create new user
        if (empty($_POST['password'])) {
            flash('error', 'A password is required for new users.');
            redirect(siteUrl('admin/users?action=new'));
        }
        $clash = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if ($clash) {
            flash('error', 'That email address is already in use.');
            redirect(siteUrl('admin/users?action=new'));
        }

        // Site assignment: super_admin can pick any site or network-wide (NULL)
        $newSiteId = Database::siteId();
        if (Auth::isSuperAdmin()) {
            $sitePost = $_POST['user_site_id'] ?? 'current';
            if ($sitePost === 'network') {
                $newSiteId = null;
            } elseif (is_numeric($sitePost)) {
                $newSiteId = (int)$sitePost;
            }
        }

        Database::insert('users', [
            'name'        => $name,
            'email'       => $email,
            'password'    => Auth::hashPassword($_POST['password']),
            'role'        => $role,
            'permissions' => $permJson,
            'site_id'     => $newSiteId,
        ]);
        flash('success', 'User created.');
        redirect(siteUrl('admin/users'));
    }
}

$action   = $_GET['action'] ?? '';
$showForm = $editUser || $action === 'new';
$users    = Database::fetchAll(
    "SELECT u.id, u.name, u.email, u.role, u.site_id, u.created_at, u.last_login, n.name AS site_name
     FROM users u
     LEFT JOIN network_sites n ON n.id = u.site_id
     WHERE u.site_id = ? OR u.site_id IS NULL
     ORDER BY u.role, u.name",
    [Database::siteId()]
);

// Group permissions by category for the form
$permGroups = [
    'Administration' => ['manage_users', 'manage_settings', 'manage_records_settings'],
    'Content'        => ['manage_content', 'manage_media', 'view_analytics'],
    'Forms & Inbox'  => ['manage_contacts', 'manage_prayers'],
    'Parish Register'=> ['view_records', 'edit_records', 'print_certificates'],
];

$roleLabels = [
    'admin'       => ['label' => 'Admin',       'color' => '#7c3aed'],
    'editor'      => ['label' => 'Editor',      'color' => '#0369a1'],
    'author'      => ['label' => 'Author',      'color' => '#065f46'],
    'parishioner' => ['label' => 'Parishioner', 'color' => '#92400e'],
];

adminLayout('User Management', function() use ($users, $editUser, $showForm, $action, $currentUserId, $permGroups, $roleLabels, $isSuperAdmin, $allSites) {
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
  <div></div>
  <a href="<?= siteUrl('admin/users?action=new') ?>" class="btn btn-primary">+ Add User</a>
</div>

<?php if ($showForm): ?>
<?php
$fu = $editUser ?? [];
$fuId = (int)($fu['id'] ?? 0);
$fuRole = $fu['role'] ?? 'author';
$fuPerms = !empty($fu['permissions']) ? json_decode($fu['permissions'], true) : [];
$isSelf = ($fuId === $currentUserId);
?>
<div class="card" style="margin-bottom:24px;">
  <div class="card-header">
    <h2 class="card-title"><?= $fuId ? 'Edit User' : 'New User' ?></h2>
  </div>

  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $fuId ?>">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
      <div>
        <div class="form-group">
          <label>Full Name <span style="color:#e65100;">*</span></label>
          <input type="text" name="name" class="form-control" required
                 value="<?= h($fu['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Email Address <span style="color:#e65100;">*</span></label>
          <input type="email" name="email" class="form-control" required
                 value="<?= h($fu['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Password <?= $fuId ? '<span style="font-weight:normal;color:#aaa;">(leave blank to keep current)</span>' : '<span style="color:#e65100;">*</span>' ?></label>
          <input type="password" name="password" class="form-control"
                 <?= !$fuId ? 'required' : '' ?> autocomplete="new-password" placeholder="••••••••">
        </div>
        <div class="form-group">
          <label>Role</label>
          <?php if ($isSelf): ?>
            <input type="hidden" name="role" value="<?= h($fuRole) ?>">
            <div style="padding:8px 12px; background:#f8f3ed; border:1px solid #e8d9c4; border-radius:4px; font-size:13px; color:#6b4226;">
              <?= h(ucfirst($fuRole)) ?> <span style="color:#aaa;">(cannot change your own role)</span>
            </div>
          <?php else: ?>
            <select name="role" id="roleSelect" class="form-control" onchange="updatePermMatrix()">
              <?php foreach ($roleLabels as $rk => $rv): ?>
                <option value="<?= $rk ?>" <?= $fuRole === $rk ? 'selected' : '' ?>><?= $rv['label'] ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <div class="form-hint">
            <strong>Admin</strong> — full access &nbsp;|&nbsp;
            <strong>Editor</strong> — content + register &nbsp;|&nbsp;
            <strong>Author</strong> — posts &amp; pages &nbsp;|&nbsp;
            <strong>Parishioner</strong> — no admin access by default
          </div>
        </div>

        <?php if ($isSuperAdmin && !$isSelf): ?>
        <div class="form-group">
          <label>Site Access</label>
          <?php
          // Determine current site_id of the user being edited
          $fuSiteId = isset($fu['site_id']) ? $fu['site_id'] : null;
          $fuSiteVal = ($fuSiteId === null) ? 'network' : (string)$fuSiteId;
          ?>
          <select name="user_site_id" class="form-control">
            <option value="network" <?= $fuSiteVal === 'network' ? 'selected' : '' ?>>
              &#127760; All Sites (Network-wide)
            </option>
            <?php foreach ($allSites as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $fuSiteVal === (string)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['name']) ?><?= $s['subdomain'] ? ' (' . h($s['subdomain']) . '.myocci.org)' : ' (main site)' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">
            Network-wide users can log in to any site's admin panel.
            Site-specific users can only access their assigned site.
          </div>
        </div>
        <?php elseif (!$isSuperAdmin): ?>
        <input type="hidden" name="user_site_id" value="current">
        <?php endif; ?>

      </div>

      <div>
        <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:10px;">
          Permission Overrides
          <span style="font-weight:normal; color:#aaa; font-size:12px;">
            — check to grant above role default, uncheck to restrict below it
          </span>
        </div>

        <?php foreach ($permGroups as $groupLabel => $groupPerms): ?>
        <div style="margin-bottom:14px;">
          <div style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#8b6226; font-weight:700; margin-bottom:6px; border-bottom:1px solid #f0e6d6; padding-bottom:3px;">
            <?= h($groupLabel) ?>
          </div>
          <?php foreach ($groupPerms as $perm):
            $def = Auth::PERMISSIONS[$perm];
            $minRole  = $def['default_role'];
            $minLevel = Auth::ROLE_HIERARCHY[$minRole] ?? 99;
            $roleLevel = Auth::ROLE_HIERARCHY[$fuRole] ?? 0;
            $roleDefault = $roleLevel >= $minLevel;
            // Effective value: override if set, else role default
            $effective = isset($fuPerms[$perm]) ? (bool)$fuPerms[$perm] : $roleDefault;
          ?>
          <label style="display:flex; align-items:flex-start; gap:8px; margin-bottom:5px; cursor:pointer; font-size:13px;"
                 class="perm-row" data-perm="<?= $perm ?>"
                 data-min-role="<?= $minRole ?>">
            <input type="checkbox" name="perm[<?= $perm ?>]" value="1"
                   <?= $effective ? 'checked' : '' ?>
                   style="margin-top:2px; flex-shrink:0;">
            <span>
              <?= h($def['label']) ?>
              <span class="perm-badge" style="font-size:10px; padding:1px 5px; border-radius:8px; background:#e8d9c4; color:#6b4226; margin-left:4px;">
                default: <?= $minRole ?>+
              </span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex; gap:10px; border-top:1px solid #e8d9c4; padding-top:16px;">
      <button type="submit" class="btn btn-primary">
        <?= $fuId ? 'Save Changes' : 'Create User' ?>
      </button>
      <a href="<?= siteUrl('admin/users') ?>" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h2 class="card-title">All Users (<?= count($users) ?>)</h2>
  </div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Site Access</th>
        <th>Last Login</th>
        <th>Joined</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
      $rl = $roleLabels[$u['role']] ?? ['label' => ucfirst($u['role']), 'color' => '#555'];
    ?>
      <tr>
        <td>
          <strong><?= h($u['name']) ?></strong>
          <?php if ($u['id'] === $currentUserId): ?>
            <span style="font-size:11px; color:#aaa; margin-left:4px;">(you)</span>
          <?php endif; ?>
        </td>
        <td><?= h($u['email']) ?></td>
        <td>
          <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px;
                       background:<?= $rl['color'] ?>22; color:<?= $rl['color'] ?>; border:1px solid <?= $rl['color'] ?>44;">
            <?= $rl['label'] ?>
          </span>
        </td>
        <td style="font-size:12px; color:#666;">
          <?php if ($u['site_id'] === null): ?>
            <span style="color:#6d28d9; font-weight:600;">&#127760; All Sites</span>
          <?php else: ?>
            <?= h($u['site_name'] ?? 'Site #' . $u['site_id']) ?>
          <?php endif; ?>
        </td>
        <td style="color:#888; font-size:13px;">
          <?= $u['last_login'] ? date('M j, Y g:i a', strtotime($u['last_login'])) : 'Never' ?>
        </td>
        <td style="color:#888; font-size:13px;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
        <td style="white-space:nowrap;">
          <a href="<?= siteUrl('admin/users?id=' . $u['id']) ?>" class="btn btn-outline" style="padding:3px 10px; font-size:12px;">Edit</a>
          <?php if ($u['id'] !== $currentUserId): ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?= h(addslashes($u['name'])) ?>? This cannot be undone.');">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button type="submit" class="btn" style="padding:3px 10px; font-size:12px; background:#fde8e8; color:#c0392b; border:1px solid #f5c6c6;">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
// When the role dropdown changes, update which permission checkboxes are checked
// to reflect the new role's defaults (preserving any explicit overrides).
const hierarchy = {parishioner: 0, author: 1, editor: 2, admin: 3};

function updatePermMatrix() {
  const role = document.getElementById('roleSelect')?.value;
  if (!role) return;
  const roleLevel = hierarchy[role] ?? 0;

  document.querySelectorAll('.perm-row').forEach(row => {
    const minRole  = row.dataset.minRole;
    const minLevel = hierarchy[minRole] ?? 99;
    const cb = row.querySelector('input[type=checkbox]');
    if (cb) cb.checked = (roleLevel >= minLevel);
  });
}
</script>

<?php }); ?>
