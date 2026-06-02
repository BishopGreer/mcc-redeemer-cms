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
        'newsletter_from_name', 'newsletter_from_email',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption',
        'posts_per_page', 'date_format', 'timezone',
        'analytics_enabled', 'analytics_exclude_admins',
        'mass_schedule', 'mass_schedule_short', 'parish_address', 'parish_phone',
        'hcaptcha_site_key', 'hcaptcha_secret_key',
        'hero_button_text', 'hero_button_url',
        'home_card1_title', 'home_card1_text', 'home_card1_link_text', 'home_card1_link_url',
        'home_card2_title', 'home_card2_text', 'home_card2_link_text', 'home_card2_link_url',
        'home_card3_title', 'home_card3_text', 'home_card3_link_text', 'home_card3_link_url',
        'contact_email', 'prayer_email',
        'form_intro_forms', 'form_intro_contact', 'form_intro_register', 'form_intro_prayer',
        'social_fb_page_id', 'social_fb_access_token', 'social_fb_page_name',
        'social_bsky_handle', 'social_bsky_app_password',
        'social_threads_user_id', 'social_threads_access_token',
        'social_mastodon_instance', 'social_mastodon_token',
        'social_auto_facebook', 'social_auto_bluesky',
        'social_auto_threads', 'social_auto_mastodon',
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

$s = fn(string $k, string $d='') => $settings[$k] ?? $d;

adminLayout('Settings', function() use ($s) {
?>

<form method="post" enctype="multipart/form-data">
  <?= csrfField() ?>

  <!-- ── Site Branding ──────────────────────────────────────────────────── -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2 class="card-title">Site Branding</h2></div>
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:20px;">

      <!-- Banner -->
      <div>
        <div class="form-group">
          <label>Site Header Banner</label>
          <?php
          $bannerPath = $s('site_banner', 'public/assets/images/site-banner.png');
          $bannerUrl  = siteUrl($bannerPath);
          ?>
          <div style="margin-bottom:10px; border:1px solid #e8d9c4; border-radius:6px; overflow:hidden; background:#f8f3ed; max-height:80px; display:flex; align-items:center; justify-content:center;">
            <img src="<?= h($bannerUrl) ?>?t=<?= time() ?>" alt="Current banner"
                 style="max-width:100%; max-height:80px; display:block; object-fit:contain;">
          </div>
          <input type="file" name="banner_file" class="form-control"
                 accept="image/png,image/webp,image/jpeg,image/avif,image/gif,image/svg+xml">
          <div class="form-hint">PNG, WebP, JPEG, AVIF, GIF, SVG &mdash; max 5 MB. Recommended: 1920&times;200–500&nbsp;px.</div>
        </div>
      </div>

      <!-- Favicon -->
      <div>
        <div class="form-group">
          <label>Favicon</label>
          <?php
          $faviconPath = $s('site_favicon', 'public/assets/images/favicon.png');
          $faviconUrl  = siteUrl($faviconPath);
          ?>
          <div style="margin-bottom:10px; border:1px solid #e8d9c4; border-radius:6px; padding:12px; background:#f8f3ed; display:flex; align-items:center; justify-content:center; min-height:60px;">
            <img src="<?= h($faviconUrl) ?>?t=<?= time() ?>" alt="Current favicon"
                 style="max-width:64px; max-height:64px; display:block;">
          </div>
          <input type="file" name="favicon_file" class="form-control"
                 accept="image/png,image/webp,image/svg+xml,image/gif">
          <div class="form-hint">PNG or SVG recommended &mdash; max 5 MB. Ideal size: 512&times;512&nbsp;px.</div>
        </div>
      </div>

      <!-- OG / Social sharing image -->
      <div>
        <div class="form-group">
          <label>Default Social Share Image</label>
          <?php
          $ogPath = $s('og_default_image', 'public/assets/images/og-default.png');
          // If og_default_image looks like a full URL (legacy), skip the preview
          $ogIsUrl = str_starts_with($ogPath, 'http');
          $ogUrl   = $ogIsUrl ? $ogPath : siteUrl($ogPath);
          ?>
          <div style="margin-bottom:10px; border:1px solid #e8d9c4; border-radius:6px; overflow:hidden; background:#f8f3ed; max-height:80px; display:flex; align-items:center; justify-content:center;">
            <img src="<?= h($ogUrl) ?>?t=<?= time() ?>" alt="OG image"
                 style="max-width:100%; max-height:80px; display:block; object-fit:contain;"
                 onerror="this.style.display='none';this.nextSibling.style.display='block'">
            <span style="display:none; color:#aaa; font-size:12px;">No image set</span>
          </div>
          <input type="file" name="og_image_file" class="form-control"
                 accept="image/png,image/webp,image/jpeg,image/avif">
          <div class="form-hint">Shown when pages are shared on social media &mdash; max 5 MB. Recommended: 1200&times;630&nbsp;px.</div>
        </div>
      </div>

    </div>
  </div>
  <!-- ──────────────────────────────────────────────────────────────────── -->

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

    <div class="card">
      <div class="card-header"><h2 class="card-title">General</h2></div>
      <div class="form-group">
        <label>Site Name</label>
        <input type="text" name="site_name" class="form-control" value="<?= h($s('site_name')) ?>">
      </div>
      <div class="form-group">
        <label>Site Tagline</label>
        <input type="text" name="site_tagline" class="form-control" value="<?= h($s('site_tagline')) ?>">
      </div>
      <div class="form-group">
        <label>Site URL</label>
        <input type="url" name="site_url" class="form-control" value="<?= h($s('site_url')) ?>">
      </div>
      <div class="form-group">
        <label>Admin Email</label>
        <input type="email" name="admin_email" class="form-control" value="<?= h($s('admin_email')) ?>">
      </div>
      <div class="form-group">
        <label>Homepage Button Text</label>
        <input type="text" name="hero_button_text" class="form-control"
               value="<?= h($s('hero_button_text', 'Mass Times & Sacraments')) ?>"
               placeholder="e.g. Mass Times &amp; Sacraments">
        <div class="form-hint">Leave blank to hide the button.</div>
      </div>
      <div class="form-group">
        <label>Homepage Button URL</label>
        <input type="text" name="hero_button_url" class="form-control"
               value="<?= h($s('hero_button_url')) ?>"
               placeholder="e.g. /mass-and-sacraments or https://…">
      </div>
      <?php
      $cardDefaults = [
        1 => ['title'=>'&#9827; Mass &amp; Sacraments', 'text'=>'Find our Mass schedule, confession times, and information about all seven sacraments.', 'link_text'=>'View Schedule &rarr;', 'link_url'=>'/mass-and-sacraments'],
        2 => ['title'=>'&#128147; Ministries',          'text'=>'Discover how you can serve and grow through our parish\'s many ministries and programs.',  'link_text'=>'Get Involved &rarr;', 'link_url'=>'/ministries'],
        3 => ['title'=>'&#128203; Parish News',         'text'=>'Stay up to date with the latest news, announcements, and reflections from our community.', 'link_text'=>'Read News &rarr;',   'link_url'=>'/blog'],
      ];
      ?>
      <div style="border-top:1px solid #e8d9c4; margin:16px 0 12px;"></div>
      <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:12px;">Homepage Welcome Cards</div>
      <?php for ($c = 1; $c <= 3; $c++): $d = $cardDefaults[$c]; ?>
        <div style="background:#fdf6ec; border:1px solid #e8d9c4; border-radius:6px; padding:12px 14px; margin-bottom:12px;">
          <div style="font-size:12px; font-weight:600; color:#6b4226; margin-bottom:8px;">Card <?= $c ?></div>
          <div class="form-group" style="margin-bottom:6px;">
            <label>Title</label>
            <input type="text" name="home_card<?= $c ?>_title" class="form-control"
                   value="<?= h($s('home_card'.$c.'_title', $d['title'])) ?>">
          </div>
          <div class="form-group" style="margin-bottom:6px;">
            <label>Description</label>
            <textarea name="home_card<?= $c ?>_text" class="form-control" rows="2"><?= h($s('home_card'.$c.'_text', $d['text'])) ?></textarea>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
            <div class="form-group" style="margin-bottom:0;">
              <label>Link Text</label>
              <input type="text" name="home_card<?= $c ?>_link_text" class="form-control"
                     value="<?= h($s('home_card'.$c.'_link_text', $d['link_text'])) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label>Link URL</label>
              <input type="text" name="home_card<?= $c ?>_link_url" class="form-control"
                     value="<?= h($s('home_card'.$c.'_link_url', $d['link_url'])) ?>">
            </div>
          </div>
        </div>
      <?php endfor; ?>
      <div style="border-top:1px solid #e8d9c4; margin:4px 0 16px;"></div>

      <div class="form-group">
        <label>Posts Per Page</label>
        <input type="number" name="posts_per_page" class="form-control" value="<?= h($s('posts_per_page', '10')) ?>" min="1" max="50">
      </div>
      <div class="form-group">
        <label>Date Format <span style="font-weight:normal; color:#aaa;">(PHP date format)</span></label>
        <input type="text" name="date_format" class="form-control" value="<?= h($s('date_format', 'F j, Y')) ?>">
        <div class="form-hint">Example: <code>F j, Y</code> = <?= date('F j, Y') ?></div>
      </div>
      <div class="form-group">
        <label>Timezone</label>
        <select name="timezone" class="form-control">
          <?php foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL) as $tz): ?>
            <option value="<?= $tz ?>" <?= $s('timezone', 'America/Chicago') === $tz ? 'selected' : '' ?>>
              <?= $tz ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">Visual Editor (Jodit)</h2></div>
        <div class="form-group">
          <div class="form-hint" style="margin-top:0;">
            Jodit is served from <code>public/assets/jodit/</code> on this server — no API key or
            external CDN required. To upgrade Jodit, replace the files in that directory.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">hCaptcha (Spam Protection)</h2></div>
        <div class="form-group">
          <label>Site Key</label>
          <input type="text" name="hcaptcha_site_key" class="form-control"
                 value="<?= h($s('hcaptcha_site_key')) ?>"
                 placeholder="Get a free key at hcaptcha.com">
        </div>
        <div class="form-group">
          <label>Secret Key</label>
          <input type="text" name="hcaptcha_secret_key" class="form-control"
                 value="<?= h($s('hcaptcha_secret_key')) ?>"
                 placeholder="Secret key from hcaptcha.com">
          <div class="form-hint">
            Free account at <strong>hcaptcha.com</strong>. Both keys are required.
            Leave blank to disable CAPTCHA on public forms.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">SMTP</h2></div>
        <div class="form-group">
          <label>SMTP Host</label>
          <input type="text" name="smtp_host" class="form-control" value="<?= h($s('smtp_host')) ?>" placeholder="smtp.example.com">
        </div>
        <div class="form-group">
          <label>SMTP Port</label>
          <input type="number" name="smtp_port" class="form-control" value="<?= h($s('smtp_port', '587')) ?>">
        </div>
        <div class="form-group">
          <label>SMTP Username</label>
          <input type="text" name="smtp_user" class="form-control" value="<?= h($s('smtp_user')) ?>">
        </div>
        <div class="form-group">
          <label>SMTP Password <span style="font-weight:normal;color:#aaa;">(leave blank to keep current)</span></label>
          <input type="password" name="smtp_pass" class="form-control" placeholder="••••••••">
        </div>
        <div class="form-group">
          <label>Encryption</label>
          <select name="smtp_encryption" class="form-control">
            <option value="tls" <?= $s('smtp_encryption')!=='ssl'?'selected':'' ?>>TLS (port 587)</option>
            <option value="ssl" <?= $s('smtp_encryption')==='ssl'?'selected':'' ?>>SSL (port 465)</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Parish Information</h2></div>
      <div class="form-group">
        <label>Parish Address</label>
        <textarea name="parish_address" class="form-control" rows="3"><?= h($s('parish_address')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="text" name="parish_phone" class="form-control" value="<?= h($s('parish_phone')) ?>">
      </div>
      <div class="form-group">
        <label>Mass Schedule (full, shown in footer)</label>
        <textarea name="mass_schedule" class="form-control" rows="4"><?= h($s('mass_schedule')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Mass Schedule Short (shown in header bar)</label>
        <input type="text" name="mass_schedule_short" class="form-control"
               value="<?= h($s('mass_schedule_short')) ?>"
               placeholder="e.g. Sunday Mass: 9:00 AM &amp; 11:00 AM">
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Form Notification Emails</h2></div>
      <p style="font-size:13px; color:#888; margin-bottom:16px;">
        Where to send email notifications when someone submits a form.
        Leave blank to use the Admin Email above.
      </p>
      <div class="form-group">
        <label>Contact Form — Send Notifications To</label>
        <input type="email" name="contact_email" class="form-control"
               value="<?= h($s('contact_email')) ?>"
               placeholder="e.g. office@yourparish.org">
        <div class="form-hint">Who receives the "Contact Us" form submissions.</div>
      </div>
      <div class="form-group">
        <label>Prayer Requests — Send Notifications To</label>
        <input type="email" name="prayer_email" class="form-control"
               value="<?= h($s('prayer_email')) ?>"
               placeholder="e.g. prayers@yourparish.org">
        <div class="form-hint">Who receives prayer request notifications.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Form Intro Text</h2></div>
      <p style="font-size:13px; color:#888; margin-bottom:16px;">
        Edit the introductory paragraph shown above each public form.
      </p>
      <div class="form-group">
        <label>Forms Landing Page</label>
        <textarea name="form_intro_forms" class="form-control" rows="2"><?= h($s('form_intro_forms')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Contact Us</label>
        <textarea name="form_intro_contact" class="form-control" rows="2"><?= h($s('form_intro_contact')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Parish Registration</label>
        <textarea name="form_intro_register" class="form-control" rows="2"><?= h($s('form_intro_register')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Prayer Request</label>
        <textarea name="form_intro_prayer" class="form-control" rows="2"><?= h($s('form_intro_prayer')) ?></textarea>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Social Media Sharing</h2></div>
      <p style="font-size:13px; color:#888; margin-bottom:16px;">
        Credentials for one-click sharing of blog posts. Leave a platform blank to disable it.
        Tokens are stored in your database — treat them like passwords.
      </p>

      <div style="border-left:3px solid #3b82f6; padding:10px 14px; background:#eff6ff; border-radius:0 4px 4px 0; margin-bottom:16px; font-size:12px; color:#1e40af; line-height:1.6;">
        <strong>Facebook (Meta Business App):</strong> Because you use a Business App, you need a
        <strong>System User token</strong> rather than an OAuth login. Here is how to get one:
        <ol style="margin:8px 0 0 16px; padding:0;">
          <li>Go to <strong>business.facebook.com</strong> &rarr; Settings &rarr; Users &rarr; System Users.</li>
          <li>Create a System User (Admin role) and assign it as an admin of your Facebook Page.</li>
          <li>Click <em>Generate New Token</em>, select your app, and grant <code>pages_manage_posts</code> and <code>pages_read_engagement</code>.</li>
          <li>Copy the token (it never expires) and paste it below.</li>
          <li>Your Page ID is found on your Facebook Page under <em>About &rarr; Page Transparency</em>.</li>
        </ol>
      </div>

      <div class="form-group">
        <label>Facebook Page Name <span style="font-weight:normal; color:#aaa;">(optional, for display)</span></label>
        <input type="text" name="social_fb_page_name" class="form-control"
               value="<?= h($s('social_fb_page_name')) ?>" placeholder="Your Parish">
      </div>
      <div class="form-group">
        <label>Facebook Page ID</label>
        <input type="text" name="social_fb_page_id" class="form-control"
               value="<?= h($s('social_fb_page_id')) ?>" placeholder="123456789012345">
        <div class="form-hint">Found on your Page under About &rarr; Page Transparency.</div>
      </div>
      <div class="form-group">
        <label>Facebook System User Access Token</label>
        <input type="text" name="social_fb_access_token" class="form-control"
               value="<?= h($s('social_fb_access_token')) ?>" placeholder="EAAB…">
        <div class="form-hint">Non-expiring token generated from Meta Business Manager &rarr; System Users.</div>
      </div>

      <?php if ($s('social_fb_page_id') && $s('social_fb_access_token')): ?>
        <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; margin-bottom:12px;">
          <span style="color:#16a34a; font-size:18px;">&#10003;</span>
          <strong style="color:#15803d;">Facebook configured</strong>
          <?php if ($s('social_fb_page_name')): ?>
            <span style="color:#555;">&mdash; <?= h($s('social_fb_page_name')) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>
          <input type="checkbox" name="social_auto_facebook" value="1"
                 <?= $s('social_auto_facebook') === '1' ? 'checked' : '' ?>>
          Auto-share new posts to this Facebook Page when published
        </label>
      </div>

      <div style="border-top:1px solid #e8d9c4; margin:16px 0;"></div>
      <div style="border-left:3px solid #0ea5e9; padding:8px 12px; background:#f0f9ff; border-radius:0 4px 4px 0; margin-bottom:16px; font-size:12px; color:#0369a1;">
        <strong>BlueSky:</strong> Create an App Password at <strong>bsky.app</strong> &rarr; Settings &rarr; Privacy and Security &rarr; App Passwords.
        Use your full handle (e.g. <code>yourparish.bsky.social</code>).
      </div>
      <div class="form-group">
        <label>BlueSky Handle</label>
        <input type="text" name="social_bsky_handle" class="form-control"
               value="<?= h($s('social_bsky_handle')) ?>" placeholder="yourparish.bsky.social">
      </div>
      <div class="form-group">
        <label>BlueSky App Password</label>
        <input type="text" name="social_bsky_app_password" class="form-control"
               value="<?= h($s('social_bsky_app_password')) ?>" placeholder="xxxx-xxxx-xxxx-xxxx">
        <div class="form-hint">Use an App Password, not your account password. 300 character limit applies.</div>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="social_auto_bluesky" value="1"
                 <?= $s('social_auto_bluesky') === '1' ? 'checked' : '' ?>>
          Auto-share new posts to this BlueSky profile when published
        </label>
      </div>

      <div style="border-top:1px solid #e8d9c4; margin:16px 0;"></div>
      <div style="border-left:3px solid #6366f1; padding:10px 14px; background:#eef2ff; border-radius:0 4px 4px 0; margin-bottom:16px; font-size:12px; color:#4338ca; line-height:1.7;">
        <strong>Threads:</strong> Get your credentials from the Graph API Explorer — no redirect URI setup needed.
        <ol style="margin:8px 0 0 16px; padding:0;">
          <li>Go to <strong>developers.facebook.com/tools/explorer</strong></li>
          <li>Select your Threads app from the app dropdown</li>
          <li>Click <em>Generate Access Token</em> and grant <code>threads_basic</code> and <code>threads_content_publish</code></li>
          <li>In the query field enter <code>me?fields=id</code> and click Submit — copy the <strong>id</strong> value as your User ID</li>
          <li>Copy the access token shown at the top — paste it below and save</li>
        </ol>
        The token expires in ~60 days. Return here to paste a fresh one when needed.
      </div>

      <div class="form-group">
        <label>Threads User ID</label>
        <input type="text" name="social_threads_user_id" class="form-control"
               value="<?= h($s('social_threads_user_id')) ?>" placeholder="123456789">
        <div class="form-hint">The numeric ID returned by <code>me?fields=id</code> in the API Explorer.</div>
      </div>
      <div class="form-group">
        <label>Threads Access Token</label>
        <input type="text" name="social_threads_access_token" class="form-control"
               value="<?= h($s('social_threads_access_token')) ?>" placeholder="THQVJz…">
        <div class="form-hint">Paste the token from the Graph API Explorer. 500 character post limit applies.</div>
      </div>

      <?php if ($s('social_threads_user_id') && $s('social_threads_access_token')): ?>
        <div style="padding:8px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; margin-bottom:12px; font-size:13px;">
          <span style="color:#16a34a;">&#10003;</span>
          <strong style="color:#15803d;">Threads configured</strong>
          &mdash; User ID: <?= h($s('social_threads_user_id')) ?>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>
          <input type="checkbox" name="social_auto_threads" value="1"
                 <?= $s('social_auto_threads') === '1' ? 'checked' : '' ?>>
          Auto-share new posts to this Threads profile when published
        </label>
      </div>

      <div style="border-top:1px solid #e8d9c4; margin:16px 0;"></div>
      <div style="border-left:3px solid #6d28d9; padding:8px 12px; background:#f5f3ff; border-radius:0 4px 4px 0; margin-bottom:16px; font-size:12px; color:#5b21b6;">
        <strong>Mastodon:</strong> On your instance, go to Preferences &rarr; Development &rarr; New Application.
        Grant <code>write:statuses</code>. Copy the Access Token.
      </div>
      <div class="form-group">
        <label>Mastodon Instance URL</label>
        <input type="url" name="social_mastodon_instance" class="form-control"
               value="<?= h($s('social_mastodon_instance')) ?>" placeholder="https://mastodon.social">
      </div>
      <div class="form-group">
        <label>Mastodon Access Token</label>
        <input type="text" name="social_mastodon_token" class="form-control"
               value="<?= h($s('social_mastodon_token')) ?>" placeholder="abc123…">
        <div class="form-hint">500 character limit applies (may vary by server).</div>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="social_auto_mastodon" value="1"
                 <?= $s('social_auto_mastodon') === '1' ? 'checked' : '' ?>>
          Auto-share new posts to this Mastodon account when published
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2 class="card-title">Analytics</h2></div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="analytics_enabled" value="1"
                 <?= $s('analytics_enabled', '1') === '1' ? 'checked' : '' ?>>
          Enable visit analytics
        </label>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="analytics_exclude_admins" value="1"
                 <?= $s('analytics_exclude_admins', '1') === '1' ? 'checked' : '' ?>>
          Exclude admin visits from analytics
        </label>
      </div>
    </div>

  </div>

  <div style="margin-top:16px;">
    <button type="submit" class="btn btn-primary" style="padding:10px 32px;">Save Settings</button>
  </div>
</form>

<?php }); ?>
