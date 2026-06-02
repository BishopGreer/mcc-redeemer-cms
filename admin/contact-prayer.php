<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once __DIR__ . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('manage_contacts');

$siteId = Database::siteId();

// -------------------------------------------------------
// POST handlers — each section has its own submit button
// with a hidden "action" field ('contact' or 'prayer').
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'contact' || $action === 'prayer') {
        $slug    = $action; // 'contact' or 'prayer'
        $enabledKey = $action . '_page_enabled';
        $enabled = !empty($_POST[$enabledKey]) ? '1' : '0';
        $content = $_POST['content'] ?? '';

        // Save enabled setting
        Database::query(
            "INSERT INTO settings (site_id, `key`, `value`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = ?",
            [$siteId, $enabledKey, $enabled, $enabled]
        );

        // Upsert the page content row
        $existing = Database::fetch(
            "SELECT id FROM pages WHERE slug = ? AND site_id = ?",
            [$slug, $siteId]
        );
        if ($existing) {
            Database::update('pages', ['content' => $content, 'status' => 'published'], 'id = ?', [$existing['id']]);
        } else {
            Database::insert('pages', [
                'site_id'     => $siteId,
                'title'       => $action === 'contact' ? 'Contact Us' : 'Prayer Request',
                'slug'        => $slug,
                'content'     => $content,
                'status'      => 'published',
                'page_type'   => 'page',
                'show_in_nav' => 0,
                'menu_order'  => 0,
                'author_id'   => Auth::id(),
            ]);
        }

        $label = $action === 'contact' ? 'Contact' : 'Prayer Request';
        flash('success', $label . ' page settings saved.');
        redirect(siteUrl('admin/contact-prayer'));
    }
}

// -------------------------------------------------------
// Load current data
// -------------------------------------------------------
$contactEnabled = setting('contact_page_enabled', '1') !== '0';
$prayerEnabled  = setting('prayer_page_enabled',  '1') !== '0';

$contactPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'contact' AND site_id = ?", [$siteId]
);
$prayerPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'prayer' AND site_id = ?", [$siteId]
);

$contactContent = $contactPage['content'] ?? '';
$prayerContent  = $prayerPage['content']  ?? '';

// -------------------------------------------------------
// Render
// -------------------------------------------------------
adminLayout('Contact &amp; Prayer Pages', function() use (
    $contactEnabled, $prayerEnabled, $contactContent, $prayerContent
) {
?>

<p style="color:#888; font-size:13px; margin-bottom:20px;">
  Manage the public <strong>Contact Us</strong> and <strong>Prayer Request</strong> pages.
  Use the editors below to add introductory text, instructions, or any custom content
  that appears above the form. Use the enable/disable toggle to hide a page from this site.
</p>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">

  <!-- =================== CONTACT =================== -->
  <div>
    <form method="post" id="contact-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="contact">

      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
          <h2 class="card-title">Contact Us Page</h2>
          <span style="font-size:12px; color:#888;">
            URL: <code><?= siteUrl('contact') ?></code>
          </span>
        </div>

        <div class="form-group">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="contact_page_enabled" value="1"
                   <?= $contactEnabled ? 'checked' : '' ?>>
            <span>
              <strong>Enable this page</strong><br>
              <span style="font-weight:400; font-size:12px; color:#888;">
                When disabled, visiting <code>/contact</code> returns a 404.
              </span>
            </span>
          </label>
        </div>

        <div style="border-top:1px solid #e8d9c4; margin:12px 0;"></div>

        <div class="form-group">
          <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">
            Page Content
            <span style="font-weight:400; color:#888;">(shown above the contact form)</span>
          </label>
          <textarea id="contact-content" name="content"><?= h($contactContent) ?></textarea>
        </div>

        <div style="margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save Contact Settings</button>
          <a href="<?= siteUrl('contact') ?>" target="_blank"
             style="margin-left:12px; font-size:13px; color:#3498db;">
            View Page &#8599;
          </a>
        </div>
      </div>
    </form>
  </div>

  <!-- =================== PRAYER =================== -->
  <div>
    <form method="post" id="prayer-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="prayer">

      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
          <h2 class="card-title">Prayer Request Page</h2>
          <span style="font-size:12px; color:#888;">
            URL: <code><?= siteUrl('prayer') ?></code>
          </span>
        </div>

        <div class="form-group">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="prayer_page_enabled" value="1"
                   <?= $prayerEnabled ? 'checked' : '' ?>>
            <span>
              <strong>Enable this page</strong><br>
              <span style="font-weight:400; font-size:12px; color:#888;">
                When disabled, visiting <code>/prayer</code> returns a 404.
              </span>
            </span>
          </label>
        </div>

        <div style="border-top:1px solid #e8d9c4; margin:12px 0;"></div>

        <div class="form-group">
          <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">
            Page Content
            <span style="font-weight:400; color:#888;">(shown above the prayer request form)</span>
          </label>
          <textarea id="prayer-content" name="content"><?= h($prayerContent) ?></textarea>
        </div>

        <div style="margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save Prayer Settings</button>
          <a href="<?= siteUrl('prayer') ?>" target="_blank"
             style="margin-left:12px; font-size:13px; color:#3498db;">
            View Page &#8599;
          </a>
        </div>
      </div>
    </form>
  </div>

</div>

<script>
// Load Jodit for both editors — local first, CDN fallback
(function() {
  var localJs = '<?= siteUrl('public/assets/jodit/jodit.min.js') ?>';
  var cdnJs   = 'https://cdn.jsdelivr.net/npm/jodit@3/build/jodit.min.js';

  ['<?= siteUrl('public/assets/jodit/jodit.min.css') ?>',
   'https://cdn.jsdelivr.net/npm/jodit@3/build/jodit.min.css'].forEach(function(href) {
    var lnk = document.createElement('link');
    lnk.rel = 'stylesheet'; lnk.href = href;
    document.head.appendChild(lnk);
  });

  var joditConfig = {
    height: 360,
    toolbarSticky: false,
    showCharsCounter: false,
    showWordsCounter: false,
    showXPathInStatusbar: false,
    buttons: [
      'undo', 'redo', '|',
      'bold', 'italic', 'underline', 'strikethrough', '|',
      'ul', 'ol', '|',
      'outdent', 'indent', '|',
      'font', 'fontsize', 'brush', 'paragraph', '|',
      'table', 'link', 'image', '|',
      'align', '|',
      'hr', 'eraser', '|',
      'fullsize', 'source'
    ],
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
    style: { fontFamily: 'Georgia, serif', fontSize: '16px', color: '#4a4a4a' },
  };

  function initEditors() {
    Jodit.make('#contact-content', joditConfig);
    Jodit.make('#prayer-content',  joditConfig);
  }

  function tryLoad(url, fallback) {
    var s = document.createElement('script');
    s.src = url;
    s.onload = initEditors;
    s.onerror = function() {
      if (fallback) { tryLoad(fallback, null); }
      else {
        document.getElementById('contact-content').rows = 12;
        document.getElementById('prayer-content').rows  = 12;
      }
    };
    document.head.appendChild(s);
  }
  tryLoad(localJs, cdnJs);
})();
</script>

<?php }); ?>
