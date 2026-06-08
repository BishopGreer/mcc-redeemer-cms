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
Auth::requireRole('editor');

$id   = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;

if (!$isNew) {
    $page = Database::fetch("SELECT * FROM pages WHERE id = ? AND site_id = ?", [$id, Database::siteId()]);
    if (!$page) { http_response_code(404); die('Page not found.'); }
} else {
    $page = [
        'id' => 0, 'title' => '', 'slug' => '', 'content' => '',
        'excerpt' => '', 'status' => 'draft', 'template' => 'default',
        'featured_image' => null, 'meta_title' => '', 'meta_desc' => '',
        'menu_order' => 0, 'show_in_nav' => 0, 'nav_label' => '', 'parent_id' => null,
        'page_type' => 'page', 'link_url' => '',
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $data = [
        'title'          => trim($_POST['title']       ?? ''),
        'slug'           => trim($_POST['slug']        ?? ''),
        'content'        => $_POST['content']          ?? '',
        'excerpt'        => trim($_POST['excerpt']     ?? ''),
        'status'         => in_array($_POST['status'] ?? '', ['published','draft','private']) ? $_POST['status'] : 'draft',
        'template'       => trim($_POST['template']    ?? 'default'),
        'featured_image' => ($_POST['featured_image'] ?? '') !== '' ? (int)$_POST['featured_image'] : null,
        'meta_title'     => trim($_POST['meta_title']  ?? ''),
        'meta_desc'      => trim($_POST['meta_desc']   ?? ''),
        'menu_order'     => (int)($_POST['menu_order'] ?? 0),
        'show_in_nav'    => !empty($_POST['show_in_nav']) ? 1 : 0,
        'nav_label'      => trim($_POST['nav_label']   ?? ''),
        'parent_id'      => ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null,
        'author_id'      => Auth::id(),
        'page_type'      => ($_POST['page_type'] ?? 'page') === 'link' ? 'link' : 'page',
        'link_url'       => trim($_POST['link_url'] ?? ''),
    ];

    if (empty($data['title'])) $errors[] = 'Title is required.';

    if (empty($errors)) {
        if (empty($data['slug'])) {
            $data['slug'] = uniqueSlug($data['title'], 'pages', $id);
        } else {
            $data['slug'] = uniqueSlug($data['slug'], 'pages', $id);
        }

        if ($isNew) {
            $data['site_id'] = Database::siteId();
            $newId = Database::insert('pages', $data);
            PageCache::clearAll();
            flash('success', 'Page created.');
            redirect(siteUrl('admin/pages/' . $newId . '/edit'));
        } else {
            Database::update('pages', $data, 'id = ?', [$id]);
            PageCache::clearAll();
            flash('success', 'Page updated.');
            redirect(siteUrl('admin/pages/' . $id . '/edit'));
        }
    }

    $page = array_merge($page, $data);
}

// Fetch all other pages for parent selector; order by menu_order for tree building
$allPagesFlat = Database::fetchAll(
    "SELECT id, title, parent_id FROM pages WHERE id != ? AND site_id = ? ORDER BY menu_order ASC, title ASC",
    [$id, Database::siteId()]
);
// Build ordered, depth-aware list for the parent <select>
$allPagesById = array_column($allPagesFlat, null, 'id');
$pagesByParent = [];
foreach ($allPagesFlat as $ap) {
    $pk = ($ap['parent_id'] && isset($allPagesById[$ap['parent_id']])) ? (int)$ap['parent_id'] : 0;
    $pagesByParent[$pk][] = $ap;
}
$allPages = [];  // will be [['id'=>, 'title'=>, 'depth'=>], ...]
$buildPageTree = function(int $parentKey, int $depth) use (&$buildPageTree, &$allPages, $pagesByParent) {
    foreach ($pagesByParent[$parentKey] ?? [] as $ap) {
        $allPages[] = ['id' => $ap['id'], 'title' => $ap['title'], 'depth' => $depth];
        $buildPageTree((int)$ap['id'], $depth + 1);
    }
};
$buildPageTree(0, 0);

adminLayout($isNew ? 'New Page' : 'Edit: ' . $page['title'], function() use ($page, $errors, $allPages, $isNew) {
?>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="post" id="page-form">
  <?= csrfField() ?>

  <div style="display:grid; grid-template-columns:1fr 280px; gap:20px;">

    <!-- Main column -->
    <div>
      <div class="card">
        <div class="form-group">
          <label for="title">Title <span style="font-size:12px;font-weight:400;color:#888;">(used as nav label if no label set)</span></label>
          <input type="text" id="title" name="title" class="form-control"
                 value="<?= h($page['title']) ?>" required
                 style="font-size:20px; padding:10px 12px;"
                 oninput="autoSlug(this.value)">
        </div>
        <div id="slug-row" class="form-group">
          <label for="slug">
            Permalink: <code><?= siteUrl('') ?></code>
            <input type="text" id="slug" name="slug" class="form-control"
                   value="<?= h($page['slug']) ?>"
                   style="display:inline; width:auto; font-size:13px;">
          </label>
        </div>
      </div>

      <div id="content-card" class="card">
        <label style="font-size:13px; font-family:sans-serif; font-weight:600; color:#4a4a4a; display:block; margin-bottom:8px;">
          Content
        </label>
        <textarea id="content" name="content"><?= h($page['content']) ?></textarea>
      </div>

      <div id="excerpt-card" class="card">
        <div class="card-header">
          <h2 class="card-title">Excerpt</h2>
        </div>
        <div class="form-group">
          <textarea name="excerpt" class="form-control" rows="3"><?= h($page['excerpt']) ?></textarea>
          <div class="form-hint">Short description shown in listings. Leave blank to auto-generate.</div>
        </div>
      </div>

      <div id="seo-card" class="card">
        <div class="card-header"><h2 class="card-title">SEO</h2></div>
        <div class="form-group">
          <label>Meta Title</label>
          <input type="text" name="meta_title" class="form-control" value="<?= h($page['meta_title']) ?>">
        </div>
        <div class="form-group">
          <label>Meta Description</label>
          <textarea name="meta_desc" class="form-control" rows="2"><?= h($page['meta_desc']) ?></textarea>
        </div>
      </div>
    </div>

    <!-- Sidebar column -->
    <div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">Publish</h2></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status" class="form-control">
            <option value="draft"     <?= $page['status'] === 'draft'     ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= $page['status'] === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="private"   <?= $page['status'] === 'private'   ? 'selected' : '' ?>>Private</option>
          </select>
        </div>
        <div style="display:flex; gap:8px; margin-top:8px;">
          <button type="submit" class="btn btn-primary" style="flex:1;">
            <?= $isNew ? 'Publish Page' : 'Update Page' ?>
          </button>
          <a href="<?= siteUrl('admin/pages') ?>" class="btn btn-secondary">Cancel</a>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Type</h2></div>
        <div class="form-group">
          <select name="page_type" id="page_type" class="form-control" onchange="togglePageType(this.value)">
            <option value="page" <?= ($page['page_type'] ?? 'page') === 'page' ? 'selected' : '' ?>>Standard Page</option>
            <option value="link" <?= ($page['page_type'] ?? '') === 'link' ? 'selected' : '' ?>>Custom Nav Link</option>
          </select>
        </div>
        <div id="link-url-group" class="form-group" style="<?= ($page['page_type'] ?? 'page') === 'link' ? '' : 'display:none;' ?>">
          <label>Link URL</label>
          <input type="text" name="link_url" id="link_url" class="form-control"
                 value="<?= h($page['link_url'] ?? '') ?>"
                 placeholder="/find-a-parish or https://example.com">
          <div class="form-hint">Use a relative path (e.g. /clergy-directory) for internal pages, or a full URL for external sites.</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Navigation</h2></div>
        <div class="form-group">
          <label>
            <input type="checkbox" name="show_in_nav" value="1"
                   <?= $page['show_in_nav'] ? 'checked' : '' ?>>
            Show in navigation menu
          </label>
        </div>
        <div class="form-group">
          <label>Nav Label</label>
          <input type="text" name="nav_label" class="form-control"
                 value="<?= h($page['nav_label']) ?>"
                 placeholder="Defaults to page title">
        </div>
        <div class="form-group">
          <label>Menu Order</label>
          <input type="number" name="menu_order" class="form-control"
                 value="<?= (int)$page['menu_order'] ?>" min="0">
        </div>
        <div class="form-group">
          <label>Parent Page</label>
          <select name="parent_id" class="form-control">
            <option value="">— None (top level) —</option>
            <?php foreach ($allPages as $ap): ?>
              <option value="<?= $ap['id'] ?>"
                      <?= (int)$page['parent_id'] === (int)$ap['id'] ? 'selected' : '' ?>>
                <?= str_repeat('— ', $ap['depth']) . h($ap['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="featured-image-card" class="card">
        <div class="card-header"><h2 class="card-title">Featured Image</h2></div>
        <div id="feat-image-preview">
          <?php if ($page['featured_image']): ?>
            <img src="<?= mediaUrl($page['featured_image'], true) ?>"
                 style="width:100%; border-radius:4px; margin-bottom:8px;">
          <?php endif; ?>
        </div>
        <input type="hidden" name="featured_image" id="featured_image"
               value="<?= (int)($page['featured_image'] ?? 0) ?: '' ?>">
        <button type="button" class="btn btn-secondary btn-sm" style="width:100%;"
                onclick="openMediaPicker('featured_image', 'feat-image-preview')">
          <?= $page['featured_image'] ? 'Change Image' : 'Set Featured Image' ?>
        </button>
        <?php if ($page['featured_image']): ?>
          <button type="button" class="btn btn-sm" style="width:100%; margin-top:4px; background:#eee;"
                  onclick="document.getElementById('featured_image').value='';
                           document.getElementById('feat-image-preview').innerHTML='';">
            Remove Image
          </button>
        <?php endif; ?>
      </div>

      <div id="template-card" class="card">
        <div class="card-header"><h2 class="card-title">Page Template</h2></div>
        <div class="form-group">
          <select name="template" class="form-control">
            <option value="default" <?= $page['template'] === 'default' ? 'selected' : '' ?>>Default</option>
            <option value="full-width" <?= $page['template'] === 'full-width' ? 'selected' : '' ?>>Full Width</option>
            <option value="sidebar" <?= $page['template'] === 'sidebar' ? 'selected' : '' ?>>With Sidebar</option>
            <option value="landing"            <?= $page['template'] === 'landing'            ? 'selected' : '' ?>>Landing Page</option>
          </select>
        </div>
      </div>
    </div>

  </div>
</form>

<script nonce="<?= cspNonce() ?>">
// Page type toggle — hide content sections for custom nav links
function togglePageType(type) {
  var isLink = type === 'link';
  ['content-card','excerpt-card','seo-card','featured-image-card','template-card'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = isLink ? 'none' : '';
  });
  var slugRow = document.getElementById('slug-row');
  if (slugRow) slugRow.style.display = isLink ? 'none' : '';
  var linkGroup = document.getElementById('link-url-group');
  if (linkGroup) linkGroup.style.display = isLink ? '' : 'none';
  if (isLink) {
    var linkUrl = document.getElementById('link_url');
    if (linkUrl && !linkUrl.value) linkUrl.focus();
  }
}
// Apply on load in case of page refresh with link type selected
togglePageType(document.getElementById('page_type').value);

// Auto-generate slug from title (only if slug is currently empty or auto-generated)
let slugManual = <?= ($page['slug'] && !$isNew) ? 'true' : 'false' ?>;
document.getElementById('slug').addEventListener('input', function() { slugManual = true; });

function autoSlug(title) {
  if (slugManual) return;
  const slug = title.toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .trim();
  document.getElementById('slug').value = slug;
}

// Load Jodit — tries local files (public/assets/jodit/) first; falls back to CDN automatically
(function() {
  var localJs  = '<?= siteUrl('public/assets/jodit/jodit.min.js') ?>';
  var cdnJs    = 'https://cdn.jsdelivr.net/npm/jodit@3/build/jodit.min.js';

  // CSS: load both so whichever is available wins (harmless to load both)
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
        'shortcodes', '|',
        'fullsize', 'source'
      ],
      extraButtons: [
      {
        name: 'mediaLibrary',
        icon: 'image',
        tooltip: 'Insert from Media Library',
        exec: function(ed) {
          window._mpField   = null;
          window._mpPreview = null;
          window._mpTinyCb  = function(url, info) {
            ed.focus();
            ed.selection.insertHTML('<img src="' + url + '" alt="' + ((info && info.alt) ? info.alt : '') + '" style="max-width:100%;">');
          };
          openMediaModal();
        }
      },
      {
        name: 'shortcodes',
        icon: 'source',
        tooltip: 'Insert Shortcode',
        list: {
          'child-pages-cards':  'Child Pages — Cards (3 col)',
          'child-pages-cards2': 'Child Pages — Cards (2 col)',
          'child-pages-list':   'Child Pages — List',
          'daily-readings':     'Daily Readings',
          'blog-excerpts':      'Blog Posts — Excerpts (3 col)',
          'blog-excerpts2':     'Blog Posts — Excerpts (2 col)',
          'blog-list':          'Blog Posts — List',
          'blog-full':          'Blog Posts — Full Content',
        },
        exec: function(ed, _el, data) {
          var map = {
            'child-pages-cards':  '[child-pages]',
            'child-pages-cards2': '[child-pages columns="2"]',
            'child-pages-list':   '[child-pages style="list"]',
            'daily-readings':     '[daily-readings]',
            'blog-excerpts':      '[blog-posts]',
            'blog-excerpts2':     '[blog-posts columns="2"]',
            'blog-list':          '[blog-posts style="list"]',
            'blog-full':          '[blog-posts style="full" count="3"]',
          };
          var tag = map[data.control.args[0]] || '';
          if (tag) { ed.focus(); ed.selection.insertHTML(tag); }
        }
      }
      ],
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

// Media picker for featured image field (keeps using local openMediaModal)
function openMediaPicker(fieldId, previewId) {
  window._mpField   = fieldId;
  window._mpPreview = previewId;
  window._mpTinyCb  = null;
  openMediaModal();
}

function openMediaModal() {
  let modal = document.getElementById('media-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'media-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
      <div style="background:#fff;border-radius:8px;width:860px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e8d9c4;">
          <strong>Media Library</strong>
          <button onclick="document.getElementById('media-modal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:12px 18px;border-bottom:1px solid #e8d9c4;display:flex;gap:10px;align-items:center;">
          <label class="btn btn-primary btn-sm" style="cursor:pointer;">
            Upload New
            <input type="file" accept="image/*,application/pdf" style="display:none;" onchange="uploadAndInsert(this)">
          </label>
          <input type="text" id="mp-search" placeholder="Search…" class="form-control" style="width:180px;" oninput="filterMedia(this.value)">
        </div>
        <div id="mp-grid" style="flex:1;overflow-y:auto;padding:16px;">
          <div class="media-grid" id="mp-items">Loading…</div>
        </div>
        <div style="padding:12px 18px;border-top:1px solid #e8d9c4;text-align:right;">
          <button class="btn btn-primary" id="mp-insert-btn" disabled onclick="insertMedia()">Insert Selected</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    loadMediaGrid();
  } else {
    modal.style.display = 'flex';
  }
  window._mpSelected = null;
  document.getElementById('mp-insert-btn').disabled = true;
}

function loadMediaGrid() {
  fetch('<?= siteUrl('api/media/list') ?>')
    .then(r => r.json())
    .then(data => {
      renderMediaGrid(data);
    });
}

let _allMedia = [];
function renderMediaGrid(items) {
  _allMedia = items;
  const grid = document.getElementById('mp-items');
  grid.innerHTML = items.map(m => {
    const isImg = m.mime_type && m.mime_type.startsWith('image/');
    const thumb = isImg
      ? `<img src="${m.thumb_url || m.url}" style="width:100%;height:100%;object-fit:cover;">`
      : `<div class="media-icon">&#128196;</div>`;
    return `<div class="media-thumb" data-id="${m.id}" data-url="${m.url}" data-alt="${m.alt_text||''}" onclick="selectMedia(this)">
      ${thumb}
      <div class="media-name">${m.original_name}</div>
    </div>`;
  }).join('');
}

function filterMedia(q) {
  const filtered = _allMedia.filter(m => m.original_name.toLowerCase().includes(q.toLowerCase()));
  renderMediaGrid(filtered);
}

function selectMedia(el) {
  document.querySelectorAll('.media-thumb.selected').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
  window._mpSelected = { id: el.dataset.id, url: el.dataset.url, alt: el.dataset.alt };
  document.getElementById('mp-insert-btn').disabled = false;
}

function insertMedia() {
  if (!window._mpSelected) return;
  const m = window._mpSelected;

  if (window._mpTinyCb) {
    window._mpTinyCb(m.url, { alt: m.alt });
  } else if (window._mpField) {
    document.getElementById(window._mpField).value = m.id;
    const prev = document.getElementById(window._mpPreview);
    if (prev) prev.innerHTML = `<img src="${m.url}" style="width:100%;border-radius:4px;margin-bottom:8px;">`;
  }
  document.getElementById('media-modal').style.display = 'none';
}

function uploadAndInsert(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  fd.append('_csrf', '<?= Auth::csrf() ?>');
  fetch('<?= siteUrl('api/media/upload') ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (data.error) { alert(data.error); return; }
      loadMediaGrid();
    });
}
</script>

<?php }); ?>
