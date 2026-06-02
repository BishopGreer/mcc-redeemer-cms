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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    $enabled = !empty($_POST['contact_page_enabled']) ? '1' : '0';
    $content = $_POST['content'] ?? '';

    Database::query(
        "INSERT INTO settings (site_id, `key`, `value`) VALUES (?, 'contact_page_enabled', ?)
         ON DUPLICATE KEY UPDATE `value` = ?",
        [$siteId, $enabled, $enabled]
    );

    $existing = Database::fetch(
        "SELECT id FROM pages WHERE slug = 'contact' AND site_id = ?", [$siteId]
    );
    if ($existing) {
        Database::update('pages', ['content' => $content, 'status' => 'published'], 'id = ?', [$existing['id']]);
    } else {
        Database::insert('pages', [
            'site_id'     => $siteId,
            'title'       => 'Contact Us',
            'slug'        => 'contact',
            'content'     => $content,
            'status'      => 'published',
            'page_type'   => 'page',
            'show_in_nav' => 0,
            'menu_order'  => 0,
            'author_id'   => Auth::id(),
        ]);
    }

    Database::clearSettingsCache();
    flash('success', 'Contact page settings saved.');
    redirect(siteUrl('admin/contact-prayer'));
}

$contactEnabled = setting('contact_page_enabled', '1') !== '0';
$contactPage    = Database::fetch("SELECT content FROM pages WHERE slug = 'contact' AND site_id = ?", [$siteId]);
$contactContent = $contactPage['content'] ?? '';

adminLayout('Contact Page', function() use ($contactEnabled, $contactContent) {
?>

<p style="color:#6b7280; font-size:13px; margin-bottom:20px;">
  Manage the public <strong>Contact Us</strong> page. Use the editor below to add
  introductory text or instructions that appear above the contact form.
</p>

<form method="post" id="contact-form">
  <?= csrfField() ?>

  <div class="card">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
      <h2 class="card-title">Contact Us Page</h2>
      <span style="font-size:12px; color:#888;">URL: <code><?= siteUrl('contact') ?></code></span>
    </div>
    <div class="card-body" style="padding:20px;">
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
      <div style="border-top:1px solid #e5e7eb; margin:16px 0;"></div>
      <div class="form-group">
        <label class="form-label">
          Page Content
          <span style="font-weight:400; color:#888;">(shown above the contact form)</span>
        </label>
        <textarea id="contact-content" name="content" rows="10"><?= h($contactContent) ?></textarea>
      </div>
      <div style="margin-top:16px; display:flex; align-items:center; gap:16px;">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <a href="<?= siteUrl('contact') ?>" target="_blank" style="font-size:13px; color:#6B3FA0;">
          View Page &#8599;
        </a>
      </div>
    </div>
  </div>
</form>

<script>
(function() {
  var localJs = '<?= siteUrl('public/assets/jodit/jodit.min.js') ?>';
  var joditConfig = {
    height: 360, toolbarSticky: false,
    showCharsCounter: false, showWordsCounter: false, showXPathInStatusbar: false,
    buttons: ['undo','redo','|','bold','italic','underline','|','ul','ol','|',
              'link','|','align','|','fullsize','source'],
    uploader: {
      url: '<?= siteUrl('api/media') ?>',
      format: 'json',
      prepareData: function(fd) { fd.append('_csrf', '<?= Auth::csrf() ?>'); return fd; },
      isSuccess: function(r) { return !!r.url; },
      getMessage: function(r) { return r.error || ''; },
      process: function(r) {
        return { files: [r.url], path: '', baseurl: '', error: r.error ? 1 : 0, msg: r.error || '' };
      },
      defaultHandlerSuccess: function(data) {
        if (data.files && data.files[0]) {
          this.j.selection.insertHTML('<img src="' + data.files[0] + '" style="max-width:100%;">');
        }
      }
    },
  };
  var s = document.createElement('script');
  s.src = localJs;
  s.onload = function() { Jodit.make('#contact-content', joditConfig); };
  document.head.appendChild(s);
})();
</script>

<?php }); ?>
