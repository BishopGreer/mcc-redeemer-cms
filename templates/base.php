<?php
/**
 * Base layout for all public pages.
 * Usage: renderPage('Page Title', fn() => { ... });
 */
function renderPage(string $title, callable $body, array $opts = []): void {
    $siteName  = setting('site_name', 'Your Parish');
    $siteTag   = setting('site_tagline', 'A Community of Faith');
    $siteBase  = rtrim(SITE_URL, '/');
    $metaDesc  = $opts['meta_desc'] ?? $siteTag;
    $metaTitle = ($title !== $siteName ? $title . ' — ' : '') . $siteName;
    $reqPath   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $canonical = $opts['canonical'] ?? $siteBase . $reqPath;
    $ogType    = $opts['og_type']   ?? 'website';

    // Only load hCaptcha JS on pages that actually have a captcha widget.
    // Pass 'hcaptcha' => true in $opts from contact/prayer/form templates.
    $loadHcaptcha = !empty($opts['hcaptcha']) && setting('hcaptcha_site_key');

    // Branding assets — use uploaded files when set, fall back to bundled defaults.
    $bannerPath  = setting('site_banner',  'public/assets/images/site-banner.png');
    $faviconPath = setting('site_favicon', 'public/assets/images/favicon.ico');
    $bannerUrl   = str_starts_with($bannerPath,  'http') ? $bannerPath  : siteUrl($bannerPath);
    $faviconUrl  = str_starts_with($faviconPath, 'http') ? $faviconPath : siteUrl($faviconPath);
    // Stored dimensions let the browser reserve exact space before download → no CLS.
    // Defaults (1920×499) match the shipped site-banner.webp; updated on each upload.
    $bannerW = (int) setting('site_banner_width',  '1920');
    $bannerH = (int) setting('site_banner_height', '499');

    // Build srcset from the responsive copies generated on upload.
    // 800w and 1280w versions save 50–75 KB on typical desktop screens.
    $bannerSrcset = '';
    if (!str_starts_with($bannerPath, 'http') && $bannerW > 0) {
        $bDir  = dirname($bannerPath);                              // public/assets/images
        $bExt  = pathinfo($bannerPath, PATHINFO_EXTENSION);        // webp
        $bBase = pathinfo($bannerPath, PATHINFO_FILENAME);         // site-banner-1
        $parts = [];
        foreach ([800, 1280] as $rw) {
            if ($rw >= $bannerW) continue;
            $rPath = $bDir . '/' . $bBase . '-' . $rw . '.' . $bExt;
            if (file_exists(BASE_PATH . '/' . $rPath)) {
                $parts[] = siteUrl($rPath) . ' ' . $rw . 'w';
            }
        }
        $parts[] = $bannerUrl . ' ' . $bannerW . 'w';
        if (count($parts) > 1) {
            $bannerSrcset = implode(', ', $parts);
        }
    }

    // CSS version string for cache-busting (changes when file is modified).
    $cssVer = @filemtime(BASE_PATH . '/public/assets/css/theme.css') ?: '';

    // OG image: per-page override → stored setting → fall back to the banner
    $ogStoredRaw = setting('og_default_image', '');
    $ogStored    = $ogStoredRaw
        ? (str_starts_with($ogStoredRaw, 'http') ? $ogStoredRaw : $siteBase . '/' . ltrim($ogStoredRaw, '/'))
        : $siteBase . '/' . ltrim($bannerPath, '/');
    $ogImage     = $opts['og_image'] ?? $ogStored;
    $jsonLd     = $opts['json_ld']   ?? null;

    $nonce = cspNonce();

    // hCaptcha is only loaded on pages where it's configured; include its domains defensively.
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}' https://hcaptcha.com https://*.hcaptcha.com",
        "style-src 'self' 'unsafe-inline' https://hcaptcha.com https://*.hcaptcha.com",
        "img-src 'self' data: https:",
        "font-src 'self'",
        "frame-src https://hcaptcha.com https://*.hcaptcha.com https://www.openstreetmap.org https://maps.google.com https://www.google.com",
        "connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
    if (!headers_sent()) {
        header("Content-Security-Policy: {$csp}");
    }

    // Navigation pages — fetch all for this site, then build multi-level tree.
    // Falls back to a simpler query if the page_type column doesn't exist yet
    // (pending migration 0024_nav_custom_links — go to /admin/updates to apply).
    try {
        $allNavPages = Database::fetchAll(
            "SELECT id, title, slug, nav_label, parent_id, page_type, link_url FROM pages
             WHERE show_in_nav = 1 AND status = 'published' AND site_id = ?
             ORDER BY menu_order ASC",
            [Database::siteId()]
        );
    } catch (\Throwable) {
        $allNavPages = Database::fetchAll(
            "SELECT id, title, slug, nav_label, parent_id,
                    'page' AS page_type, NULL AS link_url
             FROM pages
             WHERE show_in_nav = 1 AND status = 'published' AND site_id = ?
             ORDER BY menu_order ASC",
            [Database::siteId()]
        );
    }
    $navById = array_column($allNavPages, null, 'id');

    // Build parent_id → [children] map; orphaned parents fall to root (0)
    $navByParent = [];
    foreach ($allNavPages as $p) {
        $key = ($p['parent_id'] && isset($navById[$p['parent_id']])) ? (int)$p['parent_id'] : 0;
        $navByParent[$key][] = $p;
    }
    $topNavPages = $navByParent[0] ?? [];

    $currentPath = '/' . trim(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), '/');

    // Keep $navPages for footer (top-level only)
    $navPages = $topNavPages;

    // CMS forms to auto-include in the "Forms" nav dropdown.
    $cmsNavForms = [];
    try {
        $cmsNavForms = Database::fetchAll(
            "SELECT title, slug FROM custom_forms
             WHERE site_id = ? AND status = 'published'
               AND (nav_page_id IS NULL OR nav_page_id = 0)
             ORDER BY created_at ASC",
            [Database::siteId()]
        );
    } catch (\Throwable) {
        // custom_forms table may not exist yet — silently ignore
    }

    // Per-site visibility flags
    $contactEnabled = setting('contact_page_enabled', '1') !== '0';
    $blogEnabled    = setting('blog_enabled',          '0') === '1';

    // Only show the Forms dropdown if at least one item is visible
    $showFormsNav = $contactEnabled || !empty($cmsNavForms);

    // Donation links
    $paypalLink = setting('paypal_link', '');
    $venmoLink  = setting('venmo_link', '');

    // Newsletter signup
    $ccSignupEnabled = setting('newsletter_signup_enabled', '1') === '1'
                    && setting('constant_contact_api_key', '') !== '';

    // Session ID cookie for analytics duration tracking
    $analyticsSessionId = $_COOKIE['cms_asid'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($metaTitle) ?></title>
<meta name="description" content="<?= h($metaDesc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">

<!-- Open Graph -->
<meta property="og:type"        content="<?= h($ogType) ?>">
<meta property="og:site_name"   content="<?= h($siteName) ?>">
<meta property="og:title"       content="<?= h($metaTitle) ?>">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:url"         content="<?= h($canonical) ?>">
<?php if ($ogImage): ?>
<meta property="og:image"       content="<?= h($ogImage) ?>">
<?php endif; ?>
<?php if ($ogType === 'article' && !empty($opts['og_article_time'])): ?>
<meta property="article:published_time" content="<?= h($opts['og_article_time']) ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card"        content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title"       content="<?= h($metaTitle) ?>">
<meta name="twitter:description" content="<?= h($metaDesc) ?>">
<?php if ($ogImage): ?>
<meta name="twitter:image"       content="<?= h($ogImage) ?>">
<?php endif; ?>

<?php if ($jsonLd): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<!-- Preload the banner so it arrives before layout is calculated → eliminates CLS -->
<link rel="preload" as="image" href="<?= h($bannerUrl) ?>"
      <?= $bannerSrcset ? 'imagesrcset="' . h($bannerSrcset) . '" imagesizes="100vw"' : '' ?>
      fetchpriority="high">
<link rel="stylesheet" href="<?= siteUrl('public/assets/css/theme.css') ?><?= $cssVer ? '?v=' . $cssVer : '' ?>">
<link rel="icon" href="<?= h($faviconUrl) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= h($faviconUrl) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<?php if ($loadHcaptcha): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
</head>
<body>

<!-- Header -->
<header class="site-header">
  <a href="<?= siteUrl() ?>" class="site-header-link">
    <img src="<?= h($bannerUrl) ?>"
         <?= $bannerSrcset ? 'srcset="' . h($bannerSrcset) . '" sizes="100vw"' : '' ?>
         alt="<?= h($siteName) ?>"
         class="site-header-banner"
         width="<?= $bannerW ?>"
         height="<?= $bannerH ?>"
         fetchpriority="high"
         decoding="sync">
  </a>
  <button class="nav-toggle" aria-label="Menu"
          onclick="document.querySelector('.site-nav-inner').classList.toggle('open')">
    &#9776;
  </button>
</header>

<!-- Navigation -->
<nav class="site-nav" aria-label="Main navigation">
  <div class="site-nav-inner">
    <a href="<?= siteUrl() ?>" class="<?= $currentPath === '/' ? 'current' : '' ?>">Home</a>

    <?php foreach ($topNavPages as $np):
      $label    = $np['nav_label'] ?: $np['title'];
      $children = $navByParent[(int)$np['id']] ?? [];
      $isLink   = $np['page_type'] === 'link' && !empty($np['link_url']);
      $href     = $isLink ? $np['link_url'] : siteUrl($np['slug']);
      $external = $isLink && !str_starts_with($np['link_url'], '/');
      $extAttrs = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

      // Mark active if current path matches this page or any descendant
      $npActive = false;
      if (!$isLink) {
          if ($currentPath === '/' . $np['slug']) {
              $npActive = true;
          } else {
              foreach ($children as $ch) {
                  $chIsLink = $ch['page_type'] === 'link' && !empty($ch['link_url']);
                  if (!$chIsLink && $currentPath === '/' . $ch['slug']) { $npActive = true; break; }
                  foreach ($navByParent[(int)$ch['id']] ?? [] as $gc) {
                      $gcIsLink = $gc['page_type'] === 'link' && !empty($gc['link_url']);
                      if (!$gcIsLink && $currentPath === '/' . $gc['slug']) { $npActive = true; break 2; }
                  }
              }
          }
      }
      $active = $npActive ? 'current' : '';

      if ($children):
    ?>
      <div class="nav-item <?= $active ?>">
        <a href="<?= h($href) ?>" class="nav-parent-link"<?= $extAttrs ?>><?= h($label) ?></a>
        <button class="nav-dropdown-btn"
                aria-label="Expand <?= h($label) ?> submenu"
                onclick="this.closest('.nav-item').classList.toggle('open')">&#9660;</button>
        <div class="nav-dropdown">
          <?php foreach ($children as $ch):
            $chLabel      = $ch['nav_label'] ?: $ch['title'];
            $chIsLink     = $ch['page_type'] === 'link' && !empty($ch['link_url']);
            $chHref       = $chIsLink ? $ch['link_url'] : siteUrl($ch['slug']);
            $chExternal   = $chIsLink && !str_starts_with($ch['link_url'], '/');
            $chAttrs      = $chExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
            $chActive     = (!$chIsLink && $currentPath === '/' . $ch['slug']) ? 'current' : '';
            $grandchildren = $navByParent[(int)$ch['id']] ?? [];

            // Check if a grandchild is active so the child row gets highlighted too
            if (!$chActive && $grandchildren) {
                foreach ($grandchildren as $gc) {
                    $gcIsLink = $gc['page_type'] === 'link' && !empty($gc['link_url']);
                    if (!$gcIsLink && $currentPath === '/' . $gc['slug']) { $chActive = 'current'; break; }
                }
            }

            if ($grandchildren):
          ?>
            <div class="nav-sub-item <?= $chActive ?>">
              <a href="<?= h($chHref) ?>" class="nav-sub-parent-link"<?= $chAttrs ?>>
                <?= h($chLabel) ?>
              </a>
              <button class="nav-sub-dropdown-btn"
                      aria-label="Expand <?= h($chLabel) ?> submenu"
                      onclick="this.closest('.nav-sub-item').classList.toggle('open')">&#9658;</button>
              <div class="nav-sub-dropdown">
                <?php foreach ($grandchildren as $gc):
                  $gcIsLink   = $gc['page_type'] === 'link' && !empty($gc['link_url']);
                  $gcHref     = $gcIsLink ? $gc['link_url'] : siteUrl($gc['slug']);
                  $gcExternal = $gcIsLink && !str_starts_with($gc['link_url'], '/');
                  $gcAttrs    = $gcExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
                  $gcActive   = (!$gcIsLink && $currentPath === '/' . $gc['slug']) ? 'current' : '';
                ?>
                  <a href="<?= h($gcHref) ?>" class="<?= $gcActive ?>"<?= $gcAttrs ?>>
                    <?= h($gc['nav_label'] ?: $gc['title']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <a href="<?= h($chHref) ?>" class="<?= $chActive ?>"<?= $chAttrs ?>>
              <?= h($chLabel) ?>
            </a>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= h($href) ?>" class="<?= $active ?>"<?= $extAttrs ?>><?= h($label) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($blogEnabled): ?>
    <a href="<?= siteUrl('blog') ?>"
       class="<?= str_starts_with($currentPath, '/blog') ? 'current' : '' ?>">
      <?= h(setting('blog_nav_label', 'Blog')) ?>
    </a>
    <?php endif; ?>

    <?php if ($showFormsNav):
    $formsActive = (
        in_array($currentPath, ['/forms', '/contact']) ||
        str_starts_with($currentPath, '/forms/')
    ) ? 'current' : '';
    ?>
    <div class="nav-item <?= $formsActive ?>">
      <a href="<?= siteUrl('forms') ?>" class="nav-parent-link">Forms</a>
      <button class="nav-dropdown-btn" aria-label="Expand Forms submenu"
              onclick="this.closest('.nav-item').classList.toggle('open')">&#9660;</button>
      <div class="nav-dropdown">
        <?php if ($contactEnabled): ?>
        <a href="<?= siteUrl('contact') ?>" class="<?= $currentPath === '/contact' ? 'current' : '' ?>">Contact Us</a>
        <?php endif; ?>
        <?php foreach ($cmsNavForms as $cf): ?>
        <a href="<?= siteUrl('forms/' . $cf['slug']) ?>"
           class="<?= $currentPath === '/forms/' . $cf['slug'] ? 'current' : '' ?>">
          <?= h($cf['title']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</nav>

<script nonce="<?= $nonce ?>">
// Close open dropdowns when clicking outside
document.addEventListener('click', function(e) {
  if (!e.target.closest('.nav-item')) {
    document.querySelectorAll('.nav-item.open').forEach(function(el) { el.classList.remove('open'); });
  }
  if (!e.target.closest('.nav-sub-item')) {
    document.querySelectorAll('.nav-sub-item.open').forEach(function(el) { el.classList.remove('open'); });
  }
});
</script>

<!-- Main content -->
<main id="main-content">
<?php $body(); ?>
</main>

<?php if ($ccSignupEnabled): ?>
<!-- Newsletter Sign-Up Widget -->
<section class="newsletter-section" aria-label="Newsletter sign-up">
  <div class="newsletter-inner">
    <h2 class="newsletter-heading"><?= h(setting('newsletter_signup_label', 'Stay Connected — Join Our Newsletter')) ?></h2>
    <p class="newsletter-subtext">Get news and updates from MCC Our Redeemer delivered to your inbox.</p>
    <form class="newsletter-form" id="newsletter-form" novalidate>
      <div class="newsletter-fields">
        <input type="text"  name="first_name" placeholder="First name"  class="newsletter-input" autocomplete="given-name">
        <input type="text"  name="last_name"  placeholder="Last name"   class="newsletter-input" autocomplete="family-name">
        <input type="email" name="email"      placeholder="Your email"  class="newsletter-input" required autocomplete="email">
        <button type="submit" class="newsletter-btn">Subscribe</button>
      </div>
      <div id="newsletter-msg" class="newsletter-msg" role="alert" aria-live="polite"></div>
    </form>
  </div>
</section>
<script nonce="<?= $nonce ?>">
(function() {
  var form = document.getElementById('newsletter-form');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var msg = document.getElementById('newsletter-msg');
    var btn = form.querySelector('button[type=submit]');
    msg.textContent = '';
    msg.className = 'newsletter-msg';
    btn.disabled = true;
    btn.textContent = 'Subscribing…';
    var data = new FormData(form);
    fetch('<?= siteUrl('api/cc-signup') ?>', { method: 'POST', body: data })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j.ok) {
          msg.textContent = 'Thank you! You have been added to our newsletter.';
          msg.classList.add('newsletter-msg-success');
          form.reset();
        } else {
          msg.textContent = j.error || 'Something went wrong. Please try again.';
          msg.classList.add('newsletter-msg-error');
        }
        btn.disabled = false;
        btn.textContent = 'Subscribe';
      })
      .catch(function() {
        msg.textContent = 'Network error. Please try again.';
        msg.classList.add('newsletter-msg-error');
        btn.disabled = false;
        btn.textContent = 'Subscribe';
      });
  });
})();
</script>
<?php endif; ?>

<!-- Footer -->
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-col">
      <h3><?= h($siteName) ?></h3>
      <p><?= h($siteTag) ?></p>
      <?php if ($paypalLink || $venmoLink): ?>
      <div class="footer-donate" style="margin-top:16px;">
        <p style="font-weight:600; margin-bottom:10px; color:var(--color-accent-light, #F0C040);">Support Our Church</p>
        <?php if ($paypalLink): ?>
        <a href="<?= h($paypalLink) ?>" class="footer-donate-btn footer-donate-paypal"
           target="_blank" rel="noopener noreferrer">
          &#128179; Give via PayPal
        </a>
        <?php endif; ?>
        <?php if ($venmoLink): ?>
        <a href="<?= h($venmoLink) ?>" class="footer-donate-btn footer-donate-venmo"
           target="_blank" rel="noopener noreferrer">
          &#128172; Give via Venmo
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="footer-col">
      <h3>Pages</h3>
      <?php foreach ($topNavPages as $np): ?>
        <a href="<?= siteUrl($np['slug']) ?>"><?= h($np['nav_label'] ?: $np['title']) ?></a>
      <?php endforeach; ?>
      <?php if ($blogEnabled): ?>
      <a href="<?= siteUrl('blog') ?>"><?= h(setting('blog_nav_label', 'Blog')) ?></a>
      <?php endif; ?>
      <?php if ($contactEnabled): ?>
      <a href="<?= siteUrl('contact') ?>">Contact</a>
      <?php endif; ?>
      <a href="<?= siteUrl('board') ?>">Leadership</a>
    </div>
    <div class="footer-col">
      <h3>Contact Us</h3>
      <address>
        <?= h($siteName) ?><br>
        <?php if (setting('parish_address')): ?>
        <?= nl2br(h(setting('parish_address', ''))) ?><br>
        <?php endif; ?>
        <?php if (setting('parish_city') || setting('parish_state')): ?>
        <?= h(setting('parish_city', 'Augusta')) ?>, <?= h(setting('parish_state', 'GA')) ?><br>
        <?php endif; ?>
        <?php if (setting('parish_phone')): ?>
          <a href="tel:<?= h(setting('parish_phone')) ?>"><?= h(setting('parish_phone')) ?></a><br>
        <?php endif; ?>
        <?php if (setting('admin_email')): ?>
          <a href="mailto:<?= h(setting('admin_email')) ?>"><?= h(setting('admin_email')) ?></a>
        <?php endif; ?>
      </address>
    </div>
  </div>
  <div class="footer-bottom">
    &copy; <?= date('Y') ?> <?= h($siteName) ?>
    <?php $footerExtra = setting('footer_bottom_text', ''); if ($footerExtra): ?>
      &mdash; <?= h($footerExtra) ?>
    <?php endif; ?>
    &mdash; <a href="<?= siteUrl('privacy-policy') ?>">Privacy Policy</a>
    &mdash; <a href="<?= siteUrl('admin/') ?>">Admin</a>
  </div>
</footer>

<?php if (setting('analytics_enabled', '1') && $analyticsSessionId): ?>
<script nonce="<?= $nonce ?>">
// Send page duration to analytics via beacon on unload
(function() {
  var start = Date.now();
  var sid = <?= json_encode($analyticsSessionId) ?>;
  var url = <?= json_encode($_SERVER['REQUEST_URI'] ?? '/') ?>;
  function ping() {
    var dur = Math.round((Date.now() - start) / 1000);
    if (dur < 2) return;
    var data = new FormData();
    data.append('sid', sid);
    data.append('url', url);
    data.append('dur', dur);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(<?= json_encode(siteUrl('api/analytics-ping')) ?>, data);
    }
  }
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') ping();
  });
  window.addEventListener('pagehide', ping);
})();
</script>
<?php endif; ?>
</body>
</html>
<?php } ?>
