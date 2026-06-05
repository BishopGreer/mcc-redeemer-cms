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
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    // ── Branding image uploads ────────────────────────────────────────────────
    // Each site gets its own files using the site_id as a suffix so subsites
    // don't overwrite each other (e.g. site-banner-2.webp for site ID 2).
    $siteId = Database::siteId();
    $brandingUploads = [
        'site_banner'       => ['field' => 'banner_file',  'name' => 'site-banner'],
        'site_favicon'      => ['field' => 'favicon_file', 'name' => 'favicon'],
        'og_default_image'  => ['field' => 'og_image_file','name' => 'og-default'],
    ];
    $allowedMime = [
        'image/png'  => 'png',  'image/webp' => 'webp',
        'image/jpeg' => 'jpg',  'image/avif' => 'avif',
        'image/gif'  => 'gif',  'image/svg+xml' => 'svg',
    ];
    $brandingDir = BASE_PATH . '/public/assets/images';

    foreach ($brandingUploads as $settingKey => $cfg) {
        $f = $_FILES[$cfg['field']] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] === 0) continue;

        $mime = mime_content_type($f['tmp_name']);
        if (!isset($allowedMime[$mime])) {
            flash('error', 'Invalid image type for ' . $cfg['name'] . '. Allowed: PNG, WebP, JPEG, AVIF, GIF, SVG.');
            continue;
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            flash('error', ucfirst($cfg['name']) . ' image must be under 5 MB.');
            continue;
        }

        $ext      = $allowedMime[$mime];
        // Site-specific filename: e.g. site-banner-1.webp, site-banner-3.png
        $basename = $cfg['name'] . '-' . $siteId;
        $filename = $basename . '.' . $ext;
        $dest     = $brandingDir . '/' . $filename;

        // Remove this site's old branding files that used a different extension
        foreach ($allowedMime as $oldExt) {
            $old = $brandingDir . '/' . $basename . '.' . $oldExt;
            if ($old !== $dest && file_exists($old)) @unlink($old);
        }

        if (move_uploaded_file($f['tmp_name'], $dest)) {
            $relPath = 'public/assets/images/' . $filename;
            Database::query(
                "INSERT INTO settings (`site_id`, `key`, `value`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = ?",
                [$siteId, $settingKey, $relPath, $relPath]
            );
            // Store pixel dimensions + generate responsive sizes for the banner.
            if ($settingKey === 'site_banner' && $mime !== 'image/svg+xml') {
                $dims = @getimagesize($dest);
                if ($dims && $dims[0] && $dims[1]) {
                    foreach (['site_banner_width' => $dims[0], 'site_banner_height' => $dims[1]] as $dk => $dv) {
                        Database::query(
                            "INSERT INTO settings (`site_id`, `key`, `value`) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE `value` = ?",
                            [$siteId, $dk, $dv, $dv]
                        );
                    }
                    // Generate 800w and 1280w versions for srcset — reduces bandwidth
                    // for visitors on smaller screens (saves ~50–75 KB per page load).
                    $srcImg = match ($ext) {
                        'webp' => @imagecreatefromwebp($dest),
                        'jpg'  => @imagecreatefromjpeg($dest),
                        'png'  => @imagecreatefrompng($dest),
                        'gif'  => @imagecreatefromgif($dest),
                        default => null,
                    };
                    if ($srcImg) {
                        $origW = $dims[0];
                        $origH = $dims[1];
                        foreach ([800, 1280] as $tw) {
                            if ($tw >= $origW) continue; // never upscale
                            $th      = (int) round($origH * ($tw / $origW));
                            $scaled  = imagescale($srcImg, $tw, $th, IMG_BILINEAR_FIXED);
                            if (!$scaled) continue;
                            $rDest = $brandingDir . '/' . $basename . '-' . $tw . '.' . $ext;
                            match ($ext) {
                                'webp' => imagewebp($scaled, $rDest, 82),
                                'jpg'  => imagejpeg($scaled, $rDest, 82),
                                'png'  => imagepng($scaled, $rDest, 7),
                                'gif'  => imagegif($scaled, $rDest),
                                default => null,
                            };
                            imagedestroy($scaled);
                        }
                        imagedestroy($srcImg);
                    }
                }
            }
        } else {
            flash('error', 'Could not save ' . $cfg['name'] . ' — check folder permissions.');
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    $fields = [
        'site_name', 'site_tagline', 'site_url', 'admin_email',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption',
        'posts_per_page', 'date_format', 'timezone',
        'analytics_enabled', 'analytics_exclude_admins',
        'analytics_track_browser', 'analytics_track_os', 'analytics_session_minutes',
        'parish_address', 'mailing_address', 'parish_phone', 'parish_city', 'parish_state',
        'hcaptcha_site_key', 'hcaptcha_secret_key',
        'hero_button_text', 'hero_button_url',
        'home_card1_title', 'home_card1_text', 'home_card1_link_text', 'home_card1_link_url',
        'home_card2_title', 'home_card2_text', 'home_card2_link_text', 'home_card2_link_url',
        'home_card3_title', 'home_card3_text', 'home_card3_link_text', 'home_card3_link_url',
        'contact_email', 'contact_page_enabled',
        'blog_enabled', 'blog_nav_label',
        'constant_contact_api_key', 'constant_contact_list_id',
        'newsletter_signup_enabled', 'newsletter_signup_label',
        'paypal_link', 'venmo_link', 'donate_page_title', 'donate_description',
        'footer_bottom_text',
        'footer_col1_heading', 'footer_col1_content',
        'footer_col2_heading', 'footer_col2_content',
        'footer_col3_heading', 'footer_col3_content',
    ];

    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        Database::query(
            "INSERT INTO settings (`site_id`, `key`, `value`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = ?",
            [Database::siteId(), $key, $value, $value]
        );
    }

    // SMTP password only if provided
    if (!empty($_POST['smtp_pass'])) {
        Database::query(
            "INSERT INTO settings (`site_id`, `key`, `value`) VALUES (?, 'smtp_pass', ?)
             ON DUPLICATE KEY UPDATE `value` = ?",
            [Database::siteId(), $_POST['smtp_pass'], $_POST['smtp_pass']]
        );
    }

    Database::clearSettingsCache();
    PageCache::clearAll();
    flash('success', 'Settings saved.');
    redirect(siteUrl('admin/settings'));
}

// Load all settings
$settings = [];
$rows = Database::fetchAll("SELECT `key`, `value` FROM settings WHERE site_id = ?", [Database::siteId()]);
foreach ($rows as $r) $settings[$r['key']] = $r['value'];

$s = fn($k, $d='') => $settings[$k] ?? $d;

adminLayout('Settings', function() use ($settings, $s) {
?>

<form method="POST" action="" enctype="multipart/form-data">
  <?= csrfField() ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 1. SITE IDENTITY                                                        -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Site Identity</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label class="form-label">Site Name</label>
        <input type="text" name="site_name" class="form-control"
               value="<?= h($s('site_name')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Site Tagline</label>
        <input type="text" name="site_tagline" class="form-control"
               value="<?= h($s('site_tagline')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Site URL</label>
        <input type="text" name="site_url" class="form-control"
               value="<?= h($s('site_url')) ?>">
        <div class="form-hint" style="font-size:12px; color:#888; margin-top:4px;">
          To change the domain, run Domain Migration after saving.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Admin Email</label>
        <input type="email" name="admin_email" class="form-control"
               value="<?= h($s('admin_email')) ?>">
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 2. BRANDING (LOGO & BANNER)                                             -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Branding (Logo &amp; Banner)</h2></div>
    <div class="card-body" style="padding:20px;">
      <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:24px;">

        <!-- Site Banner -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Site Banner (Header Image)</label>
          <?php
            $bannerPath = $s('site_banner', '');
            $bannerUrl  = $bannerPath ? siteUrl($bannerPath) : '';
          ?>
          <?php if ($bannerUrl): ?>
            <div style="margin-bottom:8px; border:1px solid #e8d9c4; border-radius:6px; overflow:hidden; background:#f8f3ed; max-height:80px; display:flex; align-items:center; justify-content:center;">
              <img src="<?= h($bannerUrl) ?>?t=<?= time() ?>" alt="Current banner"
                   style="max-width:100%; max-height:80px; display:block; object-fit:contain;">
            </div>
            <div style="font-size:11px; color:#888; margin-bottom:6px;">Current: <?= h($bannerPath) ?></div>
          <?php else: ?>
            <div style="margin-bottom:8px; border:1px dashed #e8d9c4; border-radius:6px; padding:14px; background:#f8f3ed; text-align:center; color:#aaa; font-size:12px;">No banner set</div>
          <?php endif; ?>
          <input type="file" name="banner_file" class="form-control"
                 accept="image/png,image/webp,image/jpeg,image/avif,image/gif,image/svg+xml">
          <div style="font-size:11px; color:#888; margin-top:4px;">
            Upload your logo/header banner. Recommended: 1920&times;400&nbsp;px or wider. PNG, WebP, JPEG, SVG.
          </div>
        </div>

        <!-- Favicon -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Favicon</label>
          <?php
            $faviconPath = $s('site_favicon', '');
            $faviconUrl  = $faviconPath ? siteUrl($faviconPath) : '';
          ?>
          <?php if ($faviconUrl): ?>
            <div style="margin-bottom:8px; border:1px solid #e8d9c4; border-radius:6px; padding:10px; background:#f8f3ed; display:flex; align-items:center; justify-content:center; min-height:60px;">
              <img src="<?= h($faviconUrl) ?>?t=<?= time() ?>" alt="Current favicon"
                   style="max-width:64px; max-height:64px; display:block;">
            </div>
            <div style="font-size:11px; color:#888; margin-bottom:6px;">Current: <?= h($faviconPath) ?></div>
          <?php else: ?>
            <div style="margin-bottom:8px; border:1px dashed #e8d9c4; border-radius:6px; padding:14px; background:#f8f3ed; text-align:center; color:#aaa; font-size:12px;">No favicon set</div>
          <?php endif; ?>
          <input type="file" name="favicon_file" class="form-control"
                 accept="image/png,image/webp,image/svg+xml,image/gif">
          <div style="font-size:11px; color:#888; margin-top:4px;">
            32&times;32 or 64&times;64&nbsp;px PNG/ICO recommended.
          </div>
        </div>

        <!-- OG Share Image -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">OG Share Image</label>
          <?php
            $ogPath  = $s('og_default_image', '');
            $ogIsUrl = str_starts_with($ogPath, 'http');
            $ogUrl   = $ogPath ? ($ogIsUrl ? $ogPath : siteUrl($ogPath)) : '';
          ?>
          <?php if ($ogUrl): ?>
            <div style="margin-bottom:8px; border:1px solid #e8d9c4; border-radius:6px; overflow:hidden; background:#f8f3ed; max-height:80px; display:flex; align-items:center; justify-content:center;">
              <img src="<?= h($ogUrl) ?>?t=<?= time() ?>" alt="OG image"
                   style="max-width:100%; max-height:80px; display:block; object-fit:contain;"
                   onerror="this.style.display='none'">
            </div>
            <div style="font-size:11px; color:#888; margin-bottom:6px;">Current: <?= h($ogPath) ?></div>
          <?php else: ?>
            <div style="margin-bottom:8px; border:1px dashed #e8d9c4; border-radius:6px; padding:14px; background:#f8f3ed; text-align:center; color:#aaa; font-size:12px;">No OG image set</div>
          <?php endif; ?>
          <input type="file" name="og_image_file" class="form-control"
                 accept="image/png,image/webp,image/jpeg,image/avif">
          <div style="font-size:11px; color:#888; margin-top:4px;">
            Used when pages are shared on social media. 1200&times;630&nbsp;px.
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 3. CHURCH INFORMATION                                                   -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Church Information</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label class="form-label">Church Address</label>
        <textarea name="parish_address" class="form-control" rows="3"><?= h($s('parish_address')) ?></textarea>
        <span class="form-hint">Physical / street address shown on the contact page.</span>
      </div>
      <div class="form-group">
        <label class="form-label">Mailing Address</label>
        <input type="text" name="mailing_address" class="form-control"
               value="<?= h($s('mailing_address')) ?>"
               placeholder="e.g. P.O. Box 1234, Augusta, GA 30903">
        <span class="form-hint">PO Box or separate mailing address — shown alongside the physical address on the contact page.</span>
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="tel" name="parish_phone" class="form-control"
               value="<?= h($s('parish_phone')) ?>"
               placeholder="e.g. (555) 555-1234">
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="form-group">
          <label class="form-label">City</label>
          <input type="text" name="parish_city" class="form-control"
                 value="<?= h($s('parish_city')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">State</label>
          <input type="text" name="parish_state" class="form-control"
                 value="<?= h($s('parish_state')) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 4. BLOG                                                                 -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Blog</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="blog_enabled" value="1"
                 <?= $s('blog_enabled','1')==='1'?'checked':'' ?>>
          Enable Blog
        </label>
        <div style="font-size:12px; color:#888; margin-top:4px; margin-left:24px;">
          When disabled, the blog section is hidden from the public website and nav.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Blog Nav Label</label>
        <input type="text" name="blog_nav_label" class="form-control"
               value="<?= h($s('blog_nav_label', 'Blog')) ?>"
               placeholder="Blog">
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 5. NEWSLETTER SIGN-UP (CONSTANT CONTACT)                                -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Newsletter Sign-Up (Constant Contact)</h2></div>
    <div class="card-body" style="padding:20px;">
      <p style="font-size:13px; color:#666; margin-bottom:16px; line-height:1.6;">
        Connect to Constant Contact so visitors can subscribe to your newsletter directly from the
        website. You do not create or send emails here &mdash; that is done in Constant Contact.
      </p>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="newsletter_signup_enabled" value="1"
                 <?= $s('newsletter_signup_enabled')==='1'?'checked':'' ?>>
          Enable newsletter signup widget
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">Newsletter Signup Label</label>
        <input type="text" name="newsletter_signup_label" class="form-control"
               value="<?= h($s('newsletter_signup_label')) ?>"
               placeholder="e.g. Stay Connected — Subscribe to Our Newsletter">
        <div style="font-size:12px; color:#888; margin-top:4px;">Shown above the signup form on the website.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Constant Contact API Key</label>
        <input type="text" name="constant_contact_api_key" class="form-control"
               value="<?= h($s('constant_contact_api_key')) ?>"
               placeholder="V3 API key">
        <div style="font-size:12px; color:#888; margin-top:4px;">
          Generate a V3 API key at <strong>developer.constantcontact.com</strong>.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Constant Contact List ID</label>
        <input type="text" name="constant_contact_list_id" class="form-control"
               value="<?= h($s('constant_contact_list_id')) ?>"
               placeholder="List ID">
        <div style="font-size:12px; color:#888; margin-top:4px;">
          The ID of the list visitors will be added to. Find it in Constant Contact under
          <strong>Contacts &gt; Lists</strong>.
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 6. DONATIONS                                                             -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Donations</h2></div>
    <div class="card-body" style="padding:20px;">
      <p style="font-size:13px; color:#666; margin-bottom:16px; line-height:1.6;">
        Add your PayPal and Venmo links to display donation buttons on the site.
      </p>
      <div class="form-group">
        <label class="form-label">PayPal Donation Link</label>
        <input type="url" name="paypal_link" class="form-control"
               value="<?= h($s('paypal_link')) ?>"
               placeholder="https://www.paypal.com/donate?hosted_button_id=XXX">
      </div>
      <div class="form-group">
        <label class="form-label">Venmo Username / Link</label>
        <input type="text" name="venmo_link" class="form-control"
               value="<?= h($s('venmo_link')) ?>"
               placeholder="https://venmo.com/u/YourChurch or @YourChurch">
      </div>
      <div class="form-group">
        <label class="form-label">Donate Page Title</label>
        <input type="text" name="donate_page_title" class="form-control"
               value="<?= h($s('donate_page_title')) ?>"
               placeholder="e.g. Support Our Mission">
      </div>
      <div class="form-group">
        <label class="form-label">Donate Page Description</label>
        <textarea name="donate_description" class="form-control" rows="4"><?= h($s('donate_description')) ?></textarea>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 7. CONTACT FORM                                                          -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Contact Form</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label class="form-label">Contact Notification Email</label>
        <input type="email" name="contact_email" class="form-control"
               value="<?= h($s('contact_email')) ?>"
               placeholder="e.g. office@ourredeemer.org">
        <div style="font-size:12px; color:#888; margin-top:4px;">
          Where contact form submissions are emailed. Leave blank to use the Admin Email.
        </div>
      </div>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="contact_page_enabled" value="1"
                 <?= $s('contact_page_enabled','1')==='1'?'checked':'' ?>>
          Contact page enabled
        </label>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 8. HCAPTCHA (SPAM PROTECTION)                                           -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">hCaptcha (Spam Protection)</h2></div>
    <div class="card-body" style="padding:20px;">
      <p style="font-size:13px; color:#666; margin-bottom:16px; line-height:1.6;">
        hCaptcha protects contact forms from spam robots. Get a free account at
        <strong>hcaptcha.com</strong>.
      </p>
      <div class="form-group">
        <label class="form-label">hCaptcha Site Key</label>
        <input type="text" name="hcaptcha_site_key" class="form-control"
               value="<?= h($s('hcaptcha_site_key')) ?>"
               placeholder="Site key from hcaptcha.com">
      </div>
      <div class="form-group">
        <label class="form-label">hCaptcha Secret Key</label>
        <input type="text" name="hcaptcha_secret_key" class="form-control"
               value="<?= h($s('hcaptcha_secret_key')) ?>"
               placeholder="Secret key from hcaptcha.com">
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 9. VISUAL EDITOR (JODIT)                                                -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Visual Editor (Jodit)</h2></div>
    <div class="card-body" style="padding:20px;">
      <p style="font-size:13px; color:#666; line-height:1.6; margin:0;">
        Jodit is served from <code>public/assets/jodit/</code> on this server &mdash; no API key or
        external CDN required. To upgrade Jodit, replace the files in that directory.
      </p>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 10. SMTP EMAIL (FOR FORM NOTIFICATIONS)                                 -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">SMTP Email (for Form Notifications)</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label class="form-label">SMTP Host</label>
        <input type="text" name="smtp_host" class="form-control"
               value="<?= h($s('smtp_host')) ?>"
               placeholder="smtp.example.com">
      </div>
      <div class="form-group">
        <label class="form-label">SMTP Port</label>
        <input type="number" name="smtp_port" class="form-control"
               value="<?= h($s('smtp_port', '587')) ?>"
               placeholder="587">
      </div>
      <div class="form-group">
        <label class="form-label">SMTP Username</label>
        <input type="text" name="smtp_user" class="form-control"
               value="<?= h($s('smtp_user')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">SMTP Password
          <span style="font-weight:normal; color:#aaa;">(leave blank to keep current)</span>
        </label>
        <input type="password" name="smtp_pass" class="form-control" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
      </div>
      <div class="form-group">
        <label class="form-label">Encryption</label>
        <select name="smtp_encryption" class="form-control">
          <option value="tls" <?= $s('smtp_encryption','tls')==='tls'?'selected':'' ?>>TLS (port 587)</option>
          <option value="ssl" <?= $s('smtp_encryption')==='ssl'?'selected':'' ?>>SSL (port 465)</option>
          <option value="none" <?= $s('smtp_encryption')==='none'?'selected':'' ?>>None</option>
        </select>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 11. ANALYTICS                                                            -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Analytics</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="analytics_enabled" value="1"
                 <?= $s('analytics_enabled','1')==='1'?'checked':'' ?>>
          Enable visit analytics
        </label>
      </div>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="analytics_exclude_admins" value="1"
                 <?= $s('analytics_exclude_admins','1')==='1'?'checked':'' ?>>
          Exclude admin visits from analytics
        </label>
      </div>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="analytics_track_browser" value="1"
                 <?= $s('analytics_track_browser','1')==='1'?'checked':'' ?>>
          Track visitor browser
        </label>
      </div>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" name="analytics_track_os" value="1"
                 <?= $s('analytics_track_os','1')==='1'?'checked':'' ?>>
          Track visitor operating system
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">Session Timeout (minutes)</label>
        <input type="number" name="analytics_session_minutes" class="form-control"
               value="<?= h($s('analytics_session_minutes', '30')) ?>"
               min="1" max="1440" style="max-width:160px;">
        <div style="font-size:12px; color:#888; margin-top:4px;">
          A new session starts after this many minutes of inactivity. Default: 30.
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 12. HOMEPAGE CONTENT                                                     -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Homepage Content</h2></div>
    <div class="card-body" style="padding:20px;">

      <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:12px;">Hero Button</div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div class="form-group">
          <label class="form-label">Hero Button Text</label>
          <input type="text" name="hero_button_text" class="form-control"
                 value="<?= h($s('hero_button_text')) ?>"
                 placeholder="e.g. Learn More">
          <div style="font-size:12px; color:#888; margin-top:4px;">Leave blank to hide the button.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Hero Button URL</label>
          <input type="text" name="hero_button_url" class="form-control"
                 value="<?= h($s('hero_button_url')) ?>"
                 placeholder="e.g. /about or https://…">
        </div>
      </div>

      <div style="border-top:1px solid #e8d9c4; margin:4px 0 20px;"></div>
      <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:12px;">Feature Cards</div>

      <?php for ($c = 1; $c <= 3; $c++): ?>
        <div style="background:#fdf6ec; border:1px solid #e8d9c4; border-radius:6px; padding:16px; margin-bottom:16px;">
          <div style="font-size:12px; font-weight:700; color:#6b4226; margin-bottom:12px; text-transform:uppercase; letter-spacing:.04em;">
            Card <?= $c ?>
          </div>
          <div class="form-group">
            <label class="form-label">Title</label>
            <input type="text" name="home_card<?= $c ?>_title" class="form-control"
                   value="<?= h($s('home_card'.$c.'_title')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Text</label>
            <textarea name="home_card<?= $c ?>_text" class="form-control" rows="2"><?= h($s('home_card'.$c.'_text')) ?></textarea>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Link Text</label>
              <input type="text" name="home_card<?= $c ?>_link_text" class="form-control"
                     value="<?= h($s('home_card'.$c.'_link_text')) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Link URL</label>
              <input type="text" name="home_card<?= $c ?>_link_url" class="form-control"
                     value="<?= h($s('home_card'.$c.'_link_url')) ?>">
            </div>
          </div>
        </div>
      <?php endfor; ?>

    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 13. DATE & TIME                                                          -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Date &amp; Time</h2></div>
    <div class="card-body" style="padding:20px;">
      <div class="form-group">
        <label class="form-label">Posts Per Page</label>
        <input type="number" name="posts_per_page" class="form-control"
               value="<?= h($s('posts_per_page', '10')) ?>"
               min="1" max="100" style="max-width:160px;">
      </div>
      <div class="form-group">
        <label class="form-label">Date Format
          <span style="font-weight:normal; color:#aaa;">(PHP date format)</span>
        </label>
        <input type="text" name="date_format" class="form-control"
               value="<?= h($s('date_format', 'F j, Y')) ?>"
               style="max-width:300px;">
        <div style="font-size:12px; color:#888; margin-top:4px;">
          Examples: <code>F j, Y</code> = <?= date('F j, Y') ?> &nbsp;|&nbsp;
          <code>m/d/Y</code> = <?= date('m/d/Y') ?> &nbsp;|&nbsp;
          <code>Y-m-d</code> = <?= date('Y-m-d') ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Timezone</label>
        <select name="timezone" class="form-control">
          <?php foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL) as $tz): ?>
            <option value="<?= h($tz) ?>" <?= $s('timezone','America/Chicago')===$tz?'selected':'' ?>>
              <?= h($tz) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 14. FOOTER                                                               -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Footer</h2></div>
    <div class="card-body" style="padding:20px;">

      <p style="font-size:13px; color:#888; margin-bottom:20px;">
        The footer has three columns. Each has an editable heading and content area.
        Leave a content area blank to use the automatic default (site tagline, nav links, or church address).
        Shortcodes like <code>[events limit="3"]</code> work in content fields.
      </p>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:24px;">

        <?php foreach ([
          ['num'=>1, 'label'=>'Column 1', 'ph_head'=>'e.g. MCC Our Redeemer', 'ph_body'=>'Leave blank to show the site tagline automatically.'],
          ['num'=>2, 'label'=>'Column 2', 'ph_head'=>'e.g. Pages',            'ph_body'=>'Leave blank to show nav links automatically.'],
          ['num'=>3, 'label'=>'Column 3', 'ph_head'=>'e.g. Contact Us',       'ph_body'=>'Leave blank to show the church address automatically.'],
        ] as $fc): $n = $fc['num']; ?>
        <div>
          <div style="font-weight:600; font-size:13px; margin-bottom:10px; color:var(--brown);"><?= $fc['label'] ?></div>
          <div class="form-group">
            <label class="form-label" style="font-size:12px;">Heading</label>
            <input type="text" name="footer_col<?= $n ?>_heading" class="form-control"
                   value="<?= h($s("footer_col{$n}_heading",'')) ?>"
                   placeholder="<?= h($fc['ph_head']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" style="font-size:12px;">Content</label>
            <textarea name="footer_col<?= $n ?>_content" class="form-control"
                      rows="6" placeholder="<?= h($fc['ph_body']) ?>"><?= h($s("footer_col{$n}_content",'')) ?></textarea>
          </div>
        </div>
        <?php endforeach; ?>

      </div>

      <div style="border-top:1px solid var(--sand); padding-top:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Bottom Bar Text</label>
          <input type="text" name="footer_bottom_text" class="form-control"
                 value="<?= h($s('footer_bottom_text', '')) ?>"
                 placeholder="e.g. Metropolitan Community Church of Our Redeemer, Augusta, GA">
          <span class="form-hint">
            Shown after the copyright year and site name. Leave blank to omit.
          </span>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Save button ──────────────────────────────────────────────────────── -->
  <div style="margin-top:8px; margin-bottom:32px;">
    <button type="submit" class="btn btn-primary" style="padding:12px 40px; font-size:15px;">
      Save Settings
    </button>
  </div>

</form>

<?php });
