<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/PageCache.php';
require_once __DIR__ . '/layout.php';
PageCache::init();

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));

$id    = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;

if (!$isNew) {
    $post = Database::fetch("SELECT * FROM posts WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
    if (!$post) { http_response_code(404); die('Post not found.'); }
} else {
    $post = [
        'id'=>0,'title'=>'','slug'=>'','content'=>'','excerpt'=>'',
        'status'=>'draft','featured_image'=>null,'category_id'=>null,
        'meta_title'=>'','meta_desc'=>'','published_at'=>'',
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $data = [
        'title'          => trim($_POST['title']        ?? ''),
        'slug'           => trim($_POST['slug']         ?? ''),
        'content'        => $_POST['content']           ?? '',
        'excerpt'        => trim($_POST['excerpt']      ?? ''),
        'status'         => in_array($_POST['status'] ?? '', ['published','draft','private']) ? $_POST['status'] : 'draft',
        'featured_image' => ($_POST['featured_image'] ?? '') !== '' ? (int)$_POST['featured_image'] : null,
        'category_id'    => ($_POST['category_id']   ?? '') !== '' ? (int)$_POST['category_id']   : null,
        'meta_title'     => trim($_POST['meta_title']   ?? ''),
        'meta_desc'      => trim($_POST['meta_desc']    ?? ''),
        'published_at'   => (function() use ($post) {
                                $status     = $_POST['status'] ?? 'draft';
                                $dateInput  = trim($_POST['publish_date'] ?? '');
                                if ($status === 'published' || $status === 'private') {
                                    if ($dateInput) {
                                        // Parse the datetime-local value (Y-m-dTH:i)
                                        $ts = strtotime($dateInput);
                                        return $ts ? date('Y-m-d H:i:s', $ts) : ($post['published_at'] ?: date('Y-m-d H:i:s'));
                                    }
                                    return $post['published_at'] ?: date('Y-m-d H:i:s');
                                }
                                return null;
                            })(),
        'author_id'      => Auth::id(),
    ];

    if (empty($data['title'])) $errors[] = 'Title is required.';

    if (empty($errors)) {
        $data['slug'] = empty($data['slug'])
            ? uniqueSlug($data['title'], 'posts', $id)
            : uniqueSlug($data['slug'], 'posts', $id);

        // Detect publish transition: draft/private → published (or new post created as published)
        // Scheduled posts (published_at in the future) are NOT considered "just published"
        $wasPublished  = !$isNew && ($post['status'] === 'published');
        $isScheduled   = ($data['published_at'] ?? null) && strtotime($data['published_at']) > time();
        $justPublished = ($data['status'] === 'published') && !$wasPublished && !$isScheduled;

        if ($isNew) {
            $data['site_id'] = Database::siteId();
            $newId = Database::insert('posts', $data);
            $savedId = $newId;
        } else {
            Database::update('posts', $data, 'id = ?', [$id]);
            $savedId = $id;
        }

        $autoShared = [];
        $autoFailed = [];

        // Build flash message
        $verb = $isNew ? 'created' : 'updated';
        $msg  = 'Post ' . $verb . '.';
        if ($autoShared) $msg .= ' Shared to: ' . implode(', ', $autoShared) . '.';
        if ($autoFailed) flash('warn', 'Auto-share failed — ' . implode(' | ', $autoFailed));

        // Save tags
        if (!empty($_POST['post_tags']) && Database::fetch("SHOW TABLES LIKE 'tags'")) {
            $tagInput = trim($_POST['post_tags'] ?? '');
            $tagNames = array_filter(array_map('trim', explode(',', $tagInput)));
            $siteId   = Database::siteId();

            // Remove existing tag associations for this post
            Database::query("DELETE FROM post_tags WHERE post_id = ?", [$savedId]);

            foreach ($tagNames as $tagName) {
                if (!$tagName) continue;
                $tagSlug = slugify($tagName);
                // Find or create tag
                $tag = Database::fetch("SELECT id FROM tags WHERE site_id = ? AND slug = ?", [$siteId, $tagSlug]);
                if (!$tag) {
                    $tagId = Database::insert('tags', ['site_id' => $siteId, 'name' => $tagName, 'slug' => $tagSlug]);
                } else {
                    $tagId = $tag['id'];
                }
                Database::query(
                    "INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)",
                    [$savedId, $tagId]
                );
            }
        } elseif (isset($_POST['post_tags']) && Database::fetch("SHOW TABLES LIKE 'tags'")) {
            // Empty field — remove all tags from this post
            Database::query("DELETE FROM post_tags WHERE post_id = ?", [$savedId]);
        }

        PageCache::clearAll();
        flash('success', $msg);
        redirect(siteUrl('admin/posts/' . $savedId . '/edit'));
    }

    $post = array_merge($post, $data);
}

$categories = Database::fetchAll(
    "SELECT * FROM categories WHERE site_id = ? ORDER BY name",
    [Database::siteId()]
);

// Load tags for this post (comma-separated names for the input field)
$tagsTableExists = (bool)Database::fetch("SHOW TABLES LIKE 'tags'");
$postTagNames    = '';
if ($tagsTableExists && !$isNew) {
    $postTags = Database::fetchAll(
        "SELECT t.name FROM tags t
         JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id = ?
         ORDER BY t.name ASC",
        [$id]
    );
    $postTagNames = implode(', ', array_column($postTags, 'name'));
}
// Existing site tags for autocomplete hint
$allSiteTags = $tagsTableExists
    ? Database::fetchAll("SELECT name FROM tags WHERE site_id = ? ORDER BY name ASC", [Database::siteId()])
    : [];

adminLayout($isNew ? 'New Post' : 'Edit: ' . $post['title'], function() use ($post, $id, $errors, $categories, $isNew, $tagsTableExists, $postTagNames, $allSiteTags) {
?>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="post" id="post-form">
  <?= csrfField() ?>

  <div style="display:grid; grid-template-columns:1fr 280px; gap:20px;">
    <div>
      <div class="card">
        <div class="form-group">
          <label for="title">Post Title</label>
          <input type="text" id="title" name="title" class="form-control"
                 value="<?= h($post['title']) ?>" required
                 style="font-size:20px; padding:10px 12px;"
                 oninput="autoSlug(this.value)">
        </div>
        <div class="form-group">
          <label>
            Permalink: <code><?= siteUrl('blog/') ?></code>
            <input type="text" id="slug" name="slug" class="form-control"
                   value="<?= h($post['slug']) ?>"
                   style="display:inline; width:auto; font-size:13px;">
          </label>
        </div>
      </div>

      <div class="card">
        <label style="font-size:13px; font-family:sans-serif; font-weight:600; color:#4a4a4a; display:block; margin-bottom:8px;">Content</label>
        <textarea id="content" name="content"><?= h($post['content']) ?></textarea>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Excerpt</h2></div>
        <textarea name="excerpt" class="form-control" rows="3"><?= h($post['excerpt']) ?></textarea>
        <div class="form-hint">Short teaser shown on the blog listing page.</div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">SEO</h2></div>
        <div class="form-group">
          <label>Meta Title</label>
          <input type="text" name="meta_title" class="form-control" value="<?= h($post['meta_title']) ?>">
        </div>
        <div class="form-group">
          <label>Meta Description</label>
          <textarea name="meta_desc" class="form-control" rows="2"><?= h($post['meta_desc']) ?></textarea>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">Publish</h2></div>

        <?php
        // Determine if this post is currently scheduled
        $publishedAt   = $post['published_at'] ?? null;
        $isScheduledNow = $publishedAt && ($post['status'] === 'published') && strtotime($publishedAt) > time();
        // Format for datetime-local input  (Y-m-d\TH:i)
        $dateForInput  = $publishedAt ? date('Y-m-d\TH:i', strtotime($publishedAt)) : '';
        ?>

        <?php if ($isScheduledNow): ?>
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; color:#1e40af;">
          &#128337; <strong>Scheduled</strong> — will publish on
          <?= date('F j, Y \a\t g:i A', strtotime($publishedAt)) ?>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label>Status</label>
          <select name="status" class="form-control" id="post-status">
            <option value="draft"     <?= $post['status']==='draft'     ?'selected':'' ?>>Draft</option>
            <option value="published" <?= ($post['status']==='published' || $isScheduledNow) ?'selected':'' ?>>Published</option>
            <option value="private"   <?= $post['status']==='private'   ?'selected':'' ?>>Private</option>
          </select>
        </div>

        <div class="form-group" id="publish-date-group">
          <label>Publish Date &amp; Time</label>
          <input type="datetime-local" name="publish_date" id="publish-date"
                 class="form-control" value="<?= h($dateForInput) ?>">
          <div class="form-hint" id="publish-date-hint">
            Set a future date to schedule this post. Leave blank to publish immediately.
          </div>
        </div>

        <div style="display:flex; gap:8px; margin-top:8px;">
          <button type="submit" class="btn btn-primary" style="flex:1;" id="publish-btn">
            <?= $isNew ? 'Save Post' : 'Update Post' ?>
          </button>
          <a href="<?= siteUrl('admin/posts') ?>" class="btn btn-secondary">Cancel</a>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
          <h2 class="card-title">Category</h2>
          <a href="<?= siteUrl('admin/categories') ?>" style="font-size:11px; color:#3498db;">Manage</a>
        </div>
        <select name="category_id" class="form-control">
          <option value="">— Uncategorized —</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= (int)$post['category_id']===(int)$cat['id'] ? 'selected':'' ?>>
              <?= h($cat['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$categories): ?>
          <div class="form-hint">No categories yet. <a href="<?= siteUrl('admin/categories') ?>">Add one</a>.</div>
        <?php endif; ?>
      </div>

      <?php if ($tagsTableExists): ?>
      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
          <h2 class="card-title">Tags</h2>
          <a href="<?= siteUrl('admin/tags') ?>" style="font-size:11px; color:#3498db;">Manage</a>
        </div>
        <input type="text" name="post_tags" id="post-tags" class="form-control"
               value="<?= h($postTagNames) ?>"
               placeholder="e.g. announcements, faith, community"
               list="tag-suggestions">
        <datalist id="tag-suggestions">
          <?php foreach ($allSiteTags as $st): ?>
            <option value="<?= h($st['name']) ?>">
          <?php endforeach; ?>
        </datalist>
        <div class="form-hint">Comma-separated. New tags are created automatically.</div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Featured Image</h2></div>
        <div id="feat-image-preview">
          <?php if ($post['featured_image']): ?>
            <img src="<?= mediaUrl($post['featured_image'], true) ?>"
                 style="width:100%; border-radius:4px; margin-bottom:8px;">
          <?php endif; ?>
        </div>
        <input type="hidden" name="featured_image" id="featured_image"
               value="<?= (int)($post['featured_image'] ?? 0) ?: '' ?>">
        <button type="button" class="btn btn-secondary btn-sm" style="width:100%;"
                onclick="openMediaPicker('featured_image', 'feat-image-preview')">
          <?= $post['featured_image'] ? 'Change Image' : 'Set Featured Image' ?>
        </button>
      </div>


    </div>
  </div>
</form>

<script>
let slugManual = <?= ($post['slug'] && !$isNew) ? 'true' : 'false' ?>;
document.getElementById('slug').addEventListener('input', function() { slugManual = true; });

function autoSlug(title) {
  if (slugManual) return;
  document.getElementById('slug').value = title.toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').trim();
}

// Publish date / schedule UI
(function() {
  var statusEl  = document.getElementById('post-status');
  var dateEl    = document.getElementById('publish-date');
  var hintEl    = document.getElementById('publish-date-hint');
  var groupEl   = document.getElementById('publish-date-group');
  var btnEl     = document.getElementById('publish-btn');

  function updateUI() {
    var status    = statusEl.value;
    var dateVal   = dateEl.value;
    var isFuture  = dateVal && (new Date(dateVal) > new Date());
    var showDate  = (status === 'published' || status === 'private');

    groupEl.style.display = showDate ? '' : 'none';

    if (status === 'draft') {
      btnEl.textContent = '<?= $isNew ? 'Save Draft' : 'Update Draft' ?>';
    } else if (status === 'published' && isFuture) {
      btnEl.textContent = 'Schedule Post';
    } else {
      btnEl.textContent = '<?= $isNew ? 'Publish Post' : 'Update Post' ?>';
    }

    if (hintEl) {
      hintEl.textContent = isFuture
        ? 'This post will go live on ' + new Date(dateVal).toLocaleString() + '.'
        : 'Set a future date to schedule. Leave blank to use now.';
    }
  }

  statusEl.addEventListener('change', updateUI);
  dateEl.addEventListener('change', updateUI);
  updateUI();
})();

// Load Jodit — tries local files (public/assets/jodit/) first; falls back to CDN automatically
(function() {
  var localJs  = '<?= siteUrl('public/assets/jodit/jodit.min.js') ?>';
  var cdnJs    = 'https://cdn.jsdelivr.net/npm/jodit@3/build/jodit.min.js';

  ['<?= siteUrl('public/assets/jodit/jodit.min.css') ?>',
   'https://cdn.jsdelivr.net/npm/jodit@3/build/jodit.min.css'].forEach(function(href) {
    var lnk = document.createElement('link');
    lnk.rel = 'stylesheet'; lnk.href = href;
    document.head.appendChild(lnk);
  });

  function initEditor() {
    var editor = Jodit.make('#content', {
      height: 500,
      toolbarSticky: false,
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      uploader: {
        url: '<?= siteUrl('api/media') ?>',
        format: 'json',
        prepareData: function(fd) { fd.append('_csrf', '<?= Auth::csrf() ?>'); return fd; },
        isSuccess:   function(r)  { return !!r.url; },
        getMessage:  function(r)  { return r.error || ''; },
        process:     function(r)  {
          return { files: [r.url], path: '', baseurl: '', error: r.error ? 1 : 0, msg: r.error || '' };
        },
        defaultHandlerSuccess: function(data) {
          if (data.files && data.files[0]) {
            this.j.selection.insertHTML('<img src="' + data.files[0] + '" style="max-width:100%;">');
          }
        }
      },
      buttons: [
        'undo', 'redo', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'ul', 'ol', '|',
        'outdent', 'indent', '|',
        'font', 'fontsize', 'brush', 'paragraph', '|',
        'mediaLibrary', 'table', 'link', '|',
        'align', '|',
        'hr', 'eraser', 'copyformat', '|',
        'fullsize', 'source'
      ],
      extraButtons: [{
        name: 'mediaLibrary',
        icon: 'image',
        tooltip: 'Insert from Media Library',
        exec: function(ed) { openMediaPickerForJodit(ed); }
      }],
      style: { fontFamily: 'Georgia, serif', fontSize: '16px', color: '#4a4a4a' },
    });
    window._joditInstance = editor;
  }

  function tryLoad(url, fallback) {
    var s = document.createElement('script');
    s.src = url;
    s.onload = initEditor;
    s.onerror = function() {
      if (fallback) { tryLoad(fallback, null); }
      else { document.getElementById('content').rows = 20;
             console.error('Jodit failed to load from local path and CDN.'); }
    };
    document.head.appendChild(s);
  }
  tryLoad(localJs, cdnJs);
})();

// Media picker is provided by admin.js (loaded in layout)
</script>

<?php }); ?>
