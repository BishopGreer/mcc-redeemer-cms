<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Social.php';
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

        // Auto-share on first publish
        $autoShared = [];
        $autoFailed = [];
        if ($justPublished) {
            $savedSlug = $data['slug'];
            $postUrl   = siteUrl('blog/' . $savedSlug);
            $sharePost = array_merge($isNew ? [] : $post, $data, ['id' => $savedId]);

            // Each platform gets a message pre-truncated to its own character limit
            $platformLimits = ['facebook' => 63206, 'bluesky' => 300, 'threads' => 500, 'mastodon' => 500];
            $msg = fn(string $platform) => Social::defaultMessage($sharePost, $postUrl, $platformLimits[$platform]);

            $autoPlatforms = [
                'facebook' => [
                    'setting' => 'social_auto_facebook',
                    'fn'      => fn() => Social::shareToFacebook($msg('facebook'), $postUrl, setting('social_fb_page_id',''), setting('social_fb_access_token','')),
                ],
                'bluesky' => [
                    'setting' => 'social_auto_bluesky',
                    'fn'      => fn() => Social::shareToBlueSky($msg('bluesky'), setting('social_bsky_handle',''), setting('social_bsky_app_password','')),
                ],
                'threads' => [
                    'setting' => 'social_auto_threads',
                    'fn'      => fn() => Social::shareToThreads($msg('threads'), setting('social_threads_user_id',''), setting('social_threads_access_token','')),
                ],
                'mastodon' => [
                    'setting' => 'social_auto_mastodon',
                    'fn'      => fn() => Social::shareToMastodon($msg('mastodon'), setting('social_mastodon_instance',''), setting('social_mastodon_token','')),
                ],
            ];

            $platformLabels = ['facebook' => 'Facebook', 'bluesky' => 'BlueSky', 'threads' => 'Threads', 'mastodon' => 'Mastodon'];

            foreach ($autoPlatforms as $platform => $cfg) {
                if (setting($cfg['setting'], '0') !== '1') continue;
                $res = ($cfg['fn'])();
                Database::insert('social_shares', [
                    'post_id'          => $savedId,
                    'platform'         => $platform,
                    'status'           => $res['success'] ? 'success' : 'failed',
                    'platform_post_id' => $res['post_id'] ?? null,
                    'message'          => mb_substr($text, 0, 1000, 'UTF-8'),
                    'error_message'    => $res['error'] ?? null,
                    'shared_by'        => null, // auto, not user-initiated
                ]);
                if ($res['success']) {
                    $autoShared[] = $platformLabels[$platform];
                } else {
                    $autoFailed[] = $platformLabels[$platform] . ': ' . ($res['error'] ?? 'unknown error');
                }
            }
        }

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

// Social sharing data (only relevant for existing published posts)
$socialShares   = [];
$configuredNets = [];
$defaultShareMsg = '';
if (!$isNew && $post['status'] === 'published') {
    $socialShares = Database::fetchAll(
        "SELECT * FROM social_shares WHERE post_id = ? ORDER BY shared_at DESC", [$id]
    );
    // Which platforms have credentials configured?
    if (setting('social_fb_page_id') && setting('social_fb_access_token'))          $configuredNets[] = 'facebook';
    if (setting('social_bsky_handle') && setting('social_bsky_app_password'))        $configuredNets[] = 'bluesky';
    if (setting('social_threads_user_id') && setting('social_threads_access_token')) $configuredNets[] = 'threads';
    if (setting('social_mastodon_instance') && setting('social_mastodon_token'))     $configuredNets[] = 'mastodon';

    // Pre-truncate the default message to the tightest limit of all configured platforms
    $platformLimits = ['facebook' => 63206, 'bluesky' => 300, 'threads' => 500, 'mastodon' => 500];
    $tightest = empty($configuredNets) ? 0 : min(array_map(fn($n) => $platformLimits[$n], $configuredNets));
    $defaultShareMsg = Social::defaultMessage($post, siteUrl('blog/' . $post['slug']), $tightest);
}

adminLayout($isNew ? 'New Post' : 'Edit: ' . $post['title'], function() use ($post, $id, $errors, $categories, $isNew, $socialShares, $configuredNets, $defaultShareMsg, $tagsTableExists, $postTagNames, $allSiteTags) {
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

      <?php if (!$isNew && $post['status'] === 'published'): ?>
      <div class="card" id="social-share-card">
        <div class="card-header"><h2 class="card-title">&#128279; Share to Social</h2></div>

        <?php if (empty($configuredNets)): ?>
          <p style="font-size:13px; color:#888; margin-bottom:4px;">
            No social media accounts configured.
            <a href="<?= siteUrl('admin/settings') ?>#social">Set up in Settings &rarr;</a>
          </p>
        <?php else: ?>

          <?php
          $netMeta = [
              'facebook' => ['label' => 'Facebook',  'color' => '#1877f2', 'limit' => 63206],
              'bluesky'  => ['label' => 'BlueSky',   'color' => '#0085ff', 'limit' => 300],
              'threads'  => ['label' => 'Threads',   'color' => '#000',    'limit' => 500],
              'mastodon' => ['label' => 'Mastodon',  'color' => '#6364ff', 'limit' => 500],
          ];
          $mostRestrictive = 300; // BlueSky limit when selected

          // Last share per platform
          $lastShare = [];
          foreach ($socialShares as $sh) {
              if (!isset($lastShare[$sh['platform']])) {
                  $lastShare[$sh['platform']] = $sh;
              }
          }
          ?>

          <div style="margin-bottom:10px;">
            <?php foreach ($configuredNets as $net):
              $nm = $netMeta[$net];
              $ls = $lastShare[$net] ?? null;
            ?>
              <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer; font-size:13px;">
                <input type="checkbox" class="social-net-cb" value="<?= $net ?>" checked
                       onchange="updateCharLimit()">
                <span style="font-weight:600; color:<?= $nm['color'] ?>;"><?= $nm['label'] ?></span>
                <?php if ($ls): ?>
                  <span style="font-size:11px; color:#aaa; font-weight:normal;">
                    <?= $ls['status'] === 'success' ? '&#10003;' : '&#10007;' ?>
                    <?= date('M j \a\t g:i a', strtotime($ls['shared_at'])) ?>
                    <?php if ($ls['status'] === 'failed' && $ls['error_message']): ?>
                      — <span style="color:#c0392b;"><?= h(mb_substr($ls['error_message'], 0, 60)) ?></span>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="form-group" style="margin-bottom:6px;">
            <label style="font-size:12px;">Message</label>
            <textarea id="social-message" class="form-control" rows="6"
                      oninput="updateCharCount(); autoResizeSocialMsg(this)"
                      placeholder="Leave blank to use the post title + excerpt + link automatically."
                      style="font-size:13px; resize:vertical; overflow:hidden;"><?= h($defaultShareMsg) ?></textarea>
            <div style="font-size:11px; color:#aaa; text-align:right; margin-top:3px;">
              <span id="social-char-count">0</span> / <span id="social-char-limit"><?= $mostRestrictive ?></span>
              characters <span style="opacity:.6;">(limit of selected platforms)</span>
            </div>
          </div>

          <button type="button" id="social-share-btn" onclick="doSocialShare()"
                  class="btn btn-primary" style="width:100%; margin-top:4px;">
            Share Now
          </button>

          <div id="social-share-result" style="margin-top:10px; display:none;"></div>

          <?php if (!empty($socialShares)): ?>
          <div style="margin-top:14px; border-top:1px solid #f0e6d6; padding-top:12px;">
            <div style="font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Share History</div>
            <?php foreach (array_slice($socialShares, 0, 10) as $sh):
              $nm = $netMeta[$sh['platform']] ?? ['label' => ucfirst($sh['platform']), 'color' => '#555'];
            ?>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:12px;">
                <span style="font-weight:600; color:<?= $nm['color'] ?>; min-width:65px;"><?= $nm['label'] ?></span>
                <span style="color:<?= $sh['status'] === 'success' ? '#16a34a' : '#dc2626' ?>;">
                  <?= $sh['status'] === 'success' ? '&#10003; Shared' : '&#10007; Failed' ?>
                </span>
                <span style="color:#aaa;"><?= date('M j, Y g:i a', strtotime($sh['shared_at'])) ?></span>
                <?php if ($sh['status'] === 'failed' && $sh['error_message']): ?>
                  <span style="color:#c0392b; font-size:11px;"><?= h(mb_substr($sh['error_message'], 0, 80)) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
      <?php endif; ?>

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

// Social sharing
const socialLimits = {facebook: 63206, bluesky: 300, threads: 500, mastodon: 500};
const socialCsrf   = '<?= Auth::csrf() ?>';
const socialPostId = <?= $isNew ? 'null' : $id ?>;

function updateCharLimit() {
  let min = Infinity;
  document.querySelectorAll('.social-net-cb:checked').forEach(cb => {
    min = Math.min(min, socialLimits[cb.value] ?? Infinity);
  });
  const limitEl = document.getElementById('social-char-limit');
  if (limitEl) limitEl.textContent = min === Infinity ? '—' : min;
  updateCharCount();
}

function updateCharCount() {
  const msg = document.getElementById('social-message');
  const cnt = document.getElementById('social-char-count');
  const lim = document.getElementById('social-char-limit');
  if (!msg || !cnt) return;
  const len = [...msg.value].length; // grapheme-safe
  cnt.textContent = len;
  const limit = parseInt(lim?.textContent) || 0;
  cnt.style.color = (limit && len > limit) ? '#dc2626' : '#888';
}

function doSocialShare() {
  const platforms = [...document.querySelectorAll('.social-net-cb:checked')].map(cb => cb.value);
  if (!platforms.length) { alert('Select at least one platform.'); return; }

  const msg = document.getElementById('social-message')?.value ?? '';
  const btn = document.getElementById('social-share-btn');
  const res = document.getElementById('social-share-result');

  btn.disabled = true;
  btn.textContent = 'Sharing…';
  res.style.display = 'none';

  const body = new FormData();
  body.append('_csrf', socialCsrf);
  body.append('post_id', socialPostId);
  body.append('message', msg);
  platforms.forEach(p => body.append('platforms[]', p));

  fetch('<?= siteUrl('admin/ajax/social-share') ?>', {method: 'POST', body})
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        res.innerHTML = `<div style="color:#dc2626;font-size:13px;">&#10007; ${data.error}</div>`;
        res.style.display = 'block';
        return;
      }

      const netLabels = {facebook:'Facebook', bluesky:'BlueSky', threads:'Threads', mastodon:'Mastodon'};
      let html = '<div style="font-size:13px;">';
      let anyFailure = false;
      for (const [plat, r] of Object.entries(data.results ?? {})) {
        const label = netLabels[plat] ?? plat;
        if (r.success) {
          html += `<div style="color:#16a34a; margin-bottom:3px;">&#10003; ${label} — shared successfully</div>`;
        } else {
          html += `<div style="color:#dc2626; margin-bottom:3px;">&#10007; ${label} — ${r.error ?? 'failed'}</div>`;
          anyFailure = true;
        }
      }
      html += '</div>';
      res.innerHTML = html;
      res.style.display = 'block';

      // Only reload on full success — leave errors visible so they can be read
      if (!anyFailure) setTimeout(() => location.reload(), 2200);
    })
    .catch(() => {
      res.innerHTML = '<div style="color:#dc2626;font-size:13px;">&#10007; Network error. Check your credentials and try again.</div>';
      res.style.display = 'block';
    })
    .finally(() => {
      btn.disabled = false;
      btn.textContent = 'Share Now';
    });
}

function autoResizeSocialMsg(el) {
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

// Init char counter and textarea height on load
document.addEventListener('DOMContentLoaded', () => {
  updateCharLimit();
  updateCharCount();
  const msg = document.getElementById('social-message');
  if (msg) autoResizeSocialMsg(msg);
});
</script>

<?php }); ?>
