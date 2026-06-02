<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_content');

$siteId = Database::siteId();
$errors = [];

// -------------------------------------------------------
// POST handlers
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (!$name) {
            $errors[] = 'Category name is required.';
        } else {
            if (!$slug) $slug = slugify($name);

            // Ensure unique slug for this site
            $base = $slug; $i = 1;
            while (Database::fetch("SELECT id FROM categories WHERE site_id = ? AND slug = ?", [$siteId, $slug])) {
                $slug = $base . '-' . $i++;
            }

            Database::insert('categories', [
                'site_id'     => $siteId,
                'name'        => $name,
                'slug'        => $slug,
                'description' => $desc ?: null,
            ]);
            flash('success', 'Category "' . $name . '" added.');
            redirect(siteUrl('admin/categories'));
        }
    }

    if ($action === 'edit') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $slug  = trim($_POST['slug'] ?? '');
        $desc  = trim($_POST['description'] ?? '');

        $cat = $catId ? Database::fetch("SELECT * FROM categories WHERE id = ? AND site_id = ?", [$catId, $siteId]) : null;

        if (!$cat) {
            $errors[] = 'Category not found.';
        } elseif (!$name) {
            $errors[] = 'Category name is required.';
        } else {
            if (!$slug) $slug = slugify($name);

            $base = $slug; $i = 1;
            while (Database::fetch("SELECT id FROM categories WHERE site_id = ? AND slug = ? AND id != ?", [$siteId, $slug, $catId])) {
                $slug = $base . '-' . $i++;
            }

            Database::update('categories', [
                'name'        => $name,
                'slug'        => $slug,
                'description' => $desc ?: null,
            ], 'id = ?', [$catId]);

            flash('success', 'Category updated.');
            redirect(siteUrl('admin/categories'));
        }
    }

    if ($action === 'delete') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $cat   = $catId ? Database::fetch("SELECT * FROM categories WHERE id = ? AND site_id = ?", [$catId, $siteId]) : null;

        if ($cat) {
            // Unset on posts that use this category
            Database::query("UPDATE posts SET category_id = NULL WHERE category_id = ? AND site_id = ?", [$catId, $siteId]);
            Database::delete('categories', 'id = ?', [$catId]);
            flash('success', 'Category deleted.');
        }
        redirect(siteUrl('admin/categories'));
    }
}

// -------------------------------------------------------
// Load data
// -------------------------------------------------------
$categories = Database::fetchAll(
    "SELECT c.*, COUNT(p.id) AS post_count
     FROM categories c
     LEFT JOIN posts p ON p.category_id = c.id AND p.site_id = ?
     WHERE c.site_id = ?
     GROUP BY c.id
     ORDER BY c.name ASC",
    [$siteId, $siteId]
);

$editing = null;
if (isset($_GET['edit'])) {
    $editing = Database::fetch("SELECT * FROM categories WHERE id = ? AND site_id = ?", [(int)$_GET['edit'], $siteId]);
}

adminLayout('Categories', function() use ($categories, $errors, $editing) {
?>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 340px; gap:24px; align-items:start;">

  <!-- Category list -->
  <div class="card">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
      <h2 class="card-title">All Categories</h2>
      <span style="font-size:12px; color:#888;"><?= count($categories) ?> total</span>
    </div>

    <?php if (!$categories): ?>
      <p style="color:#888; font-style:italic; text-align:center; padding:24px 0;">
        No categories yet. Add one using the form.
      </p>
    <?php else: ?>
    <table style="width:100%; border-collapse:collapse; font-size:14px;">
      <thead>
        <tr style="border-bottom:2px solid #e8d9c4; text-align:left;">
          <th style="padding:8px 12px;">Name</th>
          <th style="padding:8px 12px;">Slug</th>
          <th style="padding:8px 12px; text-align:center;">Posts</th>
          <th style="padding:8px 12px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($categories as $cat): ?>
        <tr style="border-bottom:1px solid #f0e8da; <?= ($editing && (int)$editing['id'] === (int)$cat['id']) ? 'background:#fdf6ec;' : '' ?>">
          <td style="padding:10px 12px; font-weight:600;"><?= h($cat['name']) ?>
            <?php if ($cat['description']): ?>
              <div style="font-size:12px; font-weight:400; color:#888; margin-top:2px;"><?= h($cat['description']) ?></div>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;"><code style="font-size:12px;"><?= h($cat['slug']) ?></code></td>
          <td style="padding:10px 12px; text-align:center;"><?= (int)$cat['post_count'] ?></td>
          <td style="padding:10px 12px; text-align:right; white-space:nowrap;">
            <a href="<?= siteUrl('admin/categories?edit=' . $cat['id']) ?>"
               class="btn btn-secondary btn-sm">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete &ldquo;<?= h(addslashes($cat['name'])) ?>&rdquo;? Posts in this category will become uncategorized.')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
              <button type="submit" class="btn btn-sm"
                      style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Add / Edit form -->
  <div>
    <div class="card">
      <div class="card-header">
        <h2 class="card-title"><?= $editing ? 'Edit Category' : 'Add New Category' ?></h2>
      </div>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
        <?php if ($editing): ?>
          <input type="hidden" name="cat_id" value="<?= $editing['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label>Name <span style="color:#c0392b;">*</span></label>
          <input type="text" name="name" class="form-control" required
                 value="<?= h($editing['name'] ?? '') ?>"
                 oninput="autoSlugCat(this.value)">
        </div>
        <div class="form-group">
          <label>Slug</label>
          <input type="text" id="cat-slug" name="slug" class="form-control"
                 value="<?= h($editing['slug'] ?? '') ?>"
                 placeholder="auto-generated from name">
          <div class="form-hint">Used in URLs. Leave blank to auto-generate.</div>
        </div>
        <div class="form-group">
          <label>Description <span style="font-weight:400; color:#aaa;">(optional)</span></label>
          <textarea name="description" class="form-control" rows="2"><?= h($editing['description'] ?? '') ?></textarea>
        </div>

        <div style="display:flex; gap:8px;">
          <button type="submit" class="btn btn-primary" style="flex:1;">
            <?= $editing ? 'Save Changes' : 'Add Category' ?>
          </button>
          <?php if ($editing): ?>
            <a href="<?= siteUrl('admin/categories') ?>" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($editing): ?>
    <p style="font-size:12px; color:#aaa; text-align:center; margin-top:8px;">
      <a href="<?= siteUrl('admin/categories') ?>">&#8592; Back to all categories</a>
    </p>
    <?php endif; ?>
  </div>

</div>

<script>
var _catSlugManual = <?= $editing ? 'true' : 'false' ?>;
document.getElementById('cat-slug').addEventListener('input', function() { _catSlugManual = true; });
function autoSlugCat(val) {
  if (_catSlugManual) return;
  document.getElementById('cat-slug').value = val.toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').trim();
}
</script>

<?php }); ?>
