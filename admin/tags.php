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

        if (!$name) {
            $errors[] = 'Tag name is required.';
        } else {
            if (!$slug) $slug = slugify($name);
            $base = $slug; $i = 1;
            while (Database::fetch("SELECT id FROM tags WHERE site_id = ? AND slug = ?", [$siteId, $slug])) {
                $slug = $base . '-' . $i++;
            }
            Database::insert('tags', ['site_id' => $siteId, 'name' => $name, 'slug' => $slug]);
            flash('success', 'Tag "' . $name . '" added.');
            redirect(siteUrl('admin/tags'));
        }
    }

    if ($action === 'edit') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $slug  = trim($_POST['slug'] ?? '');
        $tag   = $tagId ? Database::fetch("SELECT * FROM tags WHERE id = ? AND site_id = ?", [$tagId, $siteId]) : null;

        if (!$tag) {
            $errors[] = 'Tag not found.';
        } elseif (!$name) {
            $errors[] = 'Tag name is required.';
        } else {
            if (!$slug) $slug = slugify($name);
            $base = $slug; $i = 1;
            while (Database::fetch("SELECT id FROM tags WHERE site_id = ? AND slug = ? AND id != ?", [$siteId, $slug, $tagId])) {
                $slug = $base . '-' . $i++;
            }
            Database::update('tags', ['name' => $name, 'slug' => $slug], 'id = ?', [$tagId]);
            flash('success', 'Tag updated.');
            redirect(siteUrl('admin/tags'));
        }
    }

    if ($action === 'delete') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        $tag   = $tagId ? Database::fetch("SELECT * FROM tags WHERE id = ? AND site_id = ?", [$tagId, $siteId]) : null;
        if ($tag) {
            Database::query("DELETE FROM post_tags WHERE tag_id = ?", [$tagId]);
            Database::delete('tags', 'id = ?', [$tagId]);
            flash('success', 'Tag deleted.');
        }
        redirect(siteUrl('admin/tags'));
    }
}

// -------------------------------------------------------
// Load data
// -------------------------------------------------------
$tagsTableExists = (bool)Database::fetch("SHOW TABLES LIKE 'tags'");

$tags = $tagsTableExists ? Database::fetchAll(
    "SELECT t.*, COUNT(pt.post_id) AS post_count
     FROM tags t
     LEFT JOIN post_tags pt ON pt.tag_id = t.id
     WHERE t.site_id = ?
     GROUP BY t.id
     ORDER BY t.name ASC",
    [$siteId]
) : [];

$editing = null;
if ($tagsTableExists && isset($_GET['edit'])) {
    $editing = Database::fetch("SELECT * FROM tags WHERE id = ? AND site_id = ?", [(int)$_GET['edit'], $siteId]);
}

adminLayout('Tags', function() use ($tags, $errors, $editing, $tagsTableExists) {
?>

<?php if (!$tagsTableExists): ?>
  <div class="alert alert-warn">
    The tags tables have not been created yet. Go to <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a> and run pending migrations first.
  </div>
<?php return; endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start;">

  <!-- Tag list -->
  <div class="card">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
      <h2 class="card-title">All Tags</h2>
      <span style="font-size:12px; color:#888;"><?= count($tags) ?> total</span>
    </div>

    <?php if (!$tags): ?>
      <p style="color:#888; font-style:italic; text-align:center; padding:24px 0;">
        No tags yet. Add one using the form.
      </p>
    <?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap:10px; padding:4px 0 16px;">
      <?php foreach ($tags as $tag): ?>
      <div style="display:flex; align-items:center; gap:6px; background:#f0e8da; border:1px solid #dfd6c7; border-radius:20px; padding:5px 12px; font-size:13px;">
        <span style="font-weight:600;"><?= h($tag['name']) ?></span>
        <span style="color:#888; font-size:11px;"><?= (int)$tag['post_count'] ?></span>
        <a href="<?= siteUrl('admin/tags?edit=' . $tag['id']) ?>"
           style="color:#6b4d1f; text-decoration:none; font-size:11px; margin-left:2px;">edit</a>
        <form method="post" style="display:inline;"
              onsubmit="return confirm('Delete tag &ldquo;<?= h(addslashes($tag['name'])) ?>&rdquo;?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
          <button type="submit"
                  style="background:none; border:none; color:#b91c1c; cursor:pointer; font-size:14px; padding:0; line-height:1;"
                  title="Delete tag">&times;</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:8px;">
      <thead>
        <tr style="border-bottom:2px solid #e8d9c4; text-align:left;">
          <th style="padding:6px 10px;">Name</th>
          <th style="padding:6px 10px;">Slug</th>
          <th style="padding:6px 10px; text-align:center;">Posts</th>
          <th style="padding:6px 10px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tags as $tag): ?>
        <tr style="border-bottom:1px solid #f0e8da; <?= ($editing && (int)$editing['id'] === (int)$tag['id']) ? 'background:#fdf6ec;' : '' ?>">
          <td style="padding:8px 10px; font-weight:600;"><?= h($tag['name']) ?></td>
          <td style="padding:8px 10px;"><code style="font-size:12px;"><?= h($tag['slug']) ?></code></td>
          <td style="padding:8px 10px; text-align:center;"><?= (int)$tag['post_count'] ?></td>
          <td style="padding:8px 10px; text-align:right; white-space:nowrap;">
            <a href="<?= siteUrl('admin/tags?edit=' . $tag['id']) ?>"
               class="btn btn-secondary btn-sm">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete &ldquo;<?= h(addslashes($tag['name'])) ?>&rdquo;?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
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
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><?= $editing ? 'Edit Tag' : 'Add New Tag' ?></h2>
    </div>

    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="tag_id" value="<?= $editing['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label>Name <span style="color:#c0392b;">*</span></label>
        <input type="text" name="name" class="form-control" required
               value="<?= h($editing['name'] ?? '') ?>"
               oninput="autoSlugTag(this.value)">
      </div>
      <div class="form-group">
        <label>Slug</label>
        <input type="text" id="tag-slug" name="slug" class="form-control"
               value="<?= h($editing['slug'] ?? '') ?>"
               placeholder="auto-generated">
      </div>

      <div style="display:flex; gap:8px;">
        <button type="submit" class="btn btn-primary" style="flex:1;">
          <?= $editing ? 'Save Changes' : 'Add Tag' ?>
        </button>
        <?php if ($editing): ?>
          <a href="<?= siteUrl('admin/tags') ?>" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

</div>

<script nonce="<?= cspNonce() ?>">
var _tagSlugManual = <?= $editing ? 'true' : 'false' ?>;
document.getElementById('tag-slug').addEventListener('input', function() { _tagSlugManual = true; });
function autoSlugTag(val) {
  if (_tagSlugManual) return;
  document.getElementById('tag-slug').value = val.toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').trim();
}
</script>

<?php }); ?>
