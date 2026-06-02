<?php
/**
 * Parish CMS — Front Controller
 * All public requests route through here via .htaccess
 */

require_once __DIR__ . '/config/config.php';

// Redirect to installer if not yet installed
if (!file_exists(BASE_PATH . '/config/install.lock')) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/install/');
    exit;
}

// ── Page cache — serve cached HTML before touching the DB ────────────────────
require_once BASE_PATH . '/core/PageCache.php';
PageCache::init();

$uri    = '/' . trim(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/');
$method = $_SERVER['REQUEST_METHOD'];

if (PageCache::shouldCache($uri)) {
    $pageCacheKey  = PageCache::key();
    $cachedHtml    = PageCache::get($pageCacheKey);
    if ($cachedHtml !== null) {
        header('X-Cache: HIT');
        header('Content-Type: text/html; charset=UTF-8');
        // Allow browsers to cache for 5 min and Cloudflare to cache for 1 hour.
        // PHP session headers (no-store etc.) are NOT sent because session never starts.
        header('Cache-Control: public, max-age=300, s-maxage=3600');
        header('Vary: Accept-Encoding');
        echo $cachedHtml;
        exit;
    }
    // Cache MISS — capture output and save once the page finishes rendering.
    $cacheDir = BASE_PATH . '/cache/pages';
    $cacheDirOk = is_dir($cacheDir) && is_writable($cacheDir);
    header('X-Cache: MISS');
    header('X-Cache-Dir: ' . ($cacheDirOk ? 'ok' : (is_dir($cacheDir) ? 'not-writable' : 'missing')));
    ob_start(static function (string $html, int $phase) use ($pageCacheKey, $cacheDirOk): string {
        if ($cacheDirOk && ($phase & PHP_OUTPUT_HANDLER_FINAL) && strlen($html) > 500) {
            PageCache::set($pageCacheKey, $html);
        }
        return $html;
    });
} else {
    $pageCacheKey = null;
    header('X-Cache: BYPASS');
}
// ─────────────────────────────────────────────────────────────────────────────

require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Analytics.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/Mailer.php';
require_once BASE_PATH . '/core/Updater.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/templates/base.php';

// Admin pages need a full read-write session (CSRF tokens, flash writes).
// Public pages only read the session (check login status, read flash messages)
// and release the lock immediately so concurrent requests don't queue.
$isAdminRequest = str_starts_with($uri, '/admin');
Auth::init(readOnly: !$isAdminRequest);

// Timing diagnostic — visible in browser DevTools (Network → Response Headers)
register_shutdown_function(function(): void {
    if (!headers_sent()) {
        $ms = round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
        header('X-Render-Time: ' . $ms . 'ms');
    }
});

// Security headers sent on every response
if (!headers_sent()) {
    // Only send HSTS over HTTPS
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
}

// -------------------------------------------------------
// Admin routes — delegate to /admin/ files
// -------------------------------------------------------
if (str_starts_with($uri, '/admin')) {
    $adminPath = preg_replace('#^/admin/?#', '', $uri);
    $parts     = explode('/', trim($adminPath, '/'));

    $section = $parts[0] ?? '';
    $id      = isset($parts[1]) ? (int)$parts[1] : 0;
    $subact  = $parts[2] ?? '';

    // Pass id/subact as query params for admin files that read $_GET
    if ($id)    $_GET['id']     = $id;
    if ($subact) $_GET['subact'] = $subact;

    match (true) {
        $section === ''             => require BASE_PATH . '/admin/index.php',
        $section === 'login'        => require BASE_PATH . '/admin/login.php',
        $section === 'logout'       => (function() { Auth::init(); Auth::logout(); redirect(siteUrl('admin/login')); })(),
        // Network admin — super_admin only, only accessible from main domain
        $section === 'network'      => (function() use ($parts) {
            Auth::init();
            Auth::requireSuperAdmin();
            $sub = $parts[1] ?? '';
            match ($sub) {
                'sites' => require BASE_PATH . '/admin/network/sites.php',
                default => require BASE_PATH . '/admin/network/index.php',
            };
        })(),
        $section === 'pages' && ($subact === 'edit' || $adminPath === 'pages/new')
                                    => require BASE_PATH . '/admin/page-edit.php',
        $section === 'pages'        => require BASE_PATH . '/admin/pages.php',
        $section === 'posts' && ($subact === 'edit' || $adminPath === 'posts/new')
                                    => require BASE_PATH . '/admin/post-edit.php',
        $section === 'posts'        => require BASE_PATH . '/admin/posts.php',
        $section === 'media'        => require BASE_PATH . '/admin/media.php',
        $section === 'analytics'    => require BASE_PATH . '/admin/analytics.php',
        $section === 'settings'     => require BASE_PATH . '/admin/settings.php',
        $section === 'domain'       => require BASE_PATH . '/admin/domain.php',
        $section === 'updates'      => require BASE_PATH . '/admin/updates.php',
        $section === 'ajax'         => require BASE_PATH . '/admin/ajax/' . (preg_replace('/[^a-z0-9-]/', '', $parts[1] ?? 'latest-post')) . '.php',
        $section === 'users'        => require BASE_PATH . '/admin/users.php',
        $section === 'social'       => (function() use ($parts) {
            $action = $parts[1] ?? '';
            match ($action) {
                'facebook-connect'  => require BASE_PATH . '/admin/social/facebook-connect.php',
                'facebook-callback' => require BASE_PATH . '/admin/social/facebook-callback.php',
                'threads-connect'   => require BASE_PATH . '/admin/social/threads-connect.php',
                'threads-callback'  => require BASE_PATH . '/admin/social/threads-callback.php',
                default             => redirect(siteUrl('admin/settings')),
            };
        })(),
        $section === 'forms'        => require BASE_PATH . '/admin/forms.php',
        $section === 'contacts'        => require BASE_PATH . '/admin/contacts.php',
        $section === 'prayers'         => require BASE_PATH . '/admin/prayers.php',
        $section === 'contact-prayer'  => require BASE_PATH . '/admin/contact-prayer.php',
        $section === 'categories'      => require BASE_PATH . '/admin/categories.php',
        $section === 'tags'            => require BASE_PATH . '/admin/tags.php',
        $section === 'parish-locator' => require BASE_PATH . '/admin/parish-locator.php',
        $section === 'clergy'           => require BASE_PATH . '/admin/clergy.php',
        $section === 'daily-readings'   => require BASE_PATH . '/admin/daily-readings.php',
        $section === 'records'      => (function() use ($parts) {
            if (Database::siteId() !== 1) {
                http_response_code(403);
                echo '<h1>Access denied.</h1><p>Sacramental Records are only available on the main site.</p>';
                return;
            }
            $sub    = $parts[1] ?? '';
            $recId  = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : 0;
            $recAct = $parts[3] ?? '';
            if (!$recAct && ($parts[2] ?? '') === 'new') $recAct = 'new';
            if ($recId)  $_GET['id']     = $recId;
            if ($recAct) $_GET['action'] = $recAct;
            $registers = ['baptisms','confirmations','marriages','deaths','communions',
                          'ordinations','ocia','attendance','donations','psr','report','certificates','parishes','settings'];
            if (in_array($sub, $registers)) {
                require BASE_PATH . '/admin/records/' . $sub . '.php';
            } elseif ($sub === 'directory' && ($recId || $recAct === 'new')) {
                require BASE_PATH . '/admin/records/directory-edit.php';
            } elseif ($sub === 'directory-print') {
                require BASE_PATH . '/admin/records/directory-print.php';
            } elseif ($sub === 'directory') {
                require BASE_PATH . '/admin/records/directory.php';
            } else {
                require BASE_PATH . '/admin/records/index.php';
            }
        })(),
        default => (function() { http_response_code(404); echo '<h1>Admin page not found.</h1>'; })(),
    };
    exit;
}

// -------------------------------------------------------
// API routes
// -------------------------------------------------------
if (str_starts_with($uri, '/api')) {
    $apiPath = preg_replace('#^/api/?#', '', $uri);
    match (true) {
        str_starts_with($apiPath, 'media') => require BASE_PATH . '/api/media.php',
        default => jsonError('Unknown API endpoint.', 404),
    };
    exit;
}

// -------------------------------------------------------
// Sitemap
// -------------------------------------------------------
if ($uri === '/sitemap.xml') {
    $base = rtrim(SITE_URL, '/');

    $staticUrls = [
        ['loc' => $base . '/',         'changefreq' => 'weekly',  'priority' => '1.0'],
        ['loc' => $base . '/blog',      'changefreq' => 'daily',   'priority' => '0.9'],
        ['loc' => $base . '/forms',          'changefreq' => 'monthly', 'priority' => '0.5'],
        ['loc' => $base . '/contact',        'changefreq' => 'monthly', 'priority' => '0.5'],
        ['loc' => $base . '/prayer',         'changefreq' => 'monthly', 'priority' => '0.5'],
        ['loc' => $base . '/find-a-parish',     'changefreq' => 'weekly',  'priority' => '0.7'],
        ['loc' => $base . '/clergy-directory', 'changefreq' => 'weekly',  'priority' => '0.7'],
        ['loc' => $base . '/daily-readings',   'changefreq' => 'daily',   'priority' => '0.8'],
    ];

    $pages = Database::fetchAll(
        "SELECT slug, updated_at FROM pages WHERE site_id = ? AND status = 'published' AND slug != 'home' ORDER BY menu_order ASC",
        [Database::siteId()]
    );
    $posts = Database::fetchAll(
        "SELECT slug, published_at, updated_at FROM posts WHERE site_id = ? AND status = 'published' AND published_at <= NOW() ORDER BY published_at DESC",
        [Database::siteId()]
    );

    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($staticUrls as $u) {
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($u['loc']) . "</loc>\n";
        echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
        echo '    <priority>' . $u['priority'] . "</priority>\n";
        echo "  </url>\n";
    }
    foreach ($pages as $p) {
        $lastmod = $p['updated_at'] ? date('Y-m-d', strtotime($p['updated_at'])) : '';
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($base . '/' . $p['slug']) . "</loc>\n";
        if ($lastmod) echo '    <lastmod>' . $lastmod . "</lastmod>\n";
        echo "    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n";
        echo "  </url>\n";
    }
    foreach ($posts as $p) {
        $date    = $p['published_at'] ?: $p['updated_at'];
        $lastmod = $date ? date('Y-m-d', strtotime($date)) : '';
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($base . '/blog/' . $p['slug']) . "</loc>\n";
        if ($lastmod) echo '    <lastmod>' . $lastmod . "</lastmod>\n";
        echo "    <changefreq>monthly</changefreq>\n    <priority>0.7</priority>\n";
        echo "  </url>\n";
    }

    echo '</urlset>';
    exit;
}

// -------------------------------------------------------
// LLMs.txt
// -------------------------------------------------------
if ($uri === '/llms.txt') {
    $base      = rtrim(SITE_URL, '/');
    $siteName  = setting('site_name', 'Your Parish');
    $siteTag   = setting('site_tagline', 'A Community of Faith');
    $phone     = setting('parish_phone', '');
    $email     = setting('admin_email', '');
    $address   = setting('parish_address', '');

    $pages = Database::fetchAll(
        "SELECT title, slug, meta_desc, excerpt FROM pages
         WHERE site_id = ? AND status = 'published' AND slug != 'home'
         ORDER BY menu_order ASC",
        [Database::siteId()]
    );
    $posts = Database::fetchAll(
        "SELECT title, slug, excerpt, published_at FROM posts
         WHERE site_id = ? AND status = 'published' AND published_at <= NOW()
         ORDER BY published_at DESC LIMIT 20",
        [Database::siteId()]
    );

    header('Content-Type: text/plain; charset=UTF-8');

    echo "# {$siteName}\n\n";
    echo "> {$siteTag}\n\n";
    echo "{$siteName} is a Roman Catholic parish in the Catholic tradition. ";
    echo "This file helps AI systems understand our site's structure and content.\n\n";

    if ($address || $phone || $email) {
        echo "## Contact\n\n";
        if ($address) echo "- Address: {$address}\n";
        if ($phone)   echo "- Phone: {$phone}\n";
        if ($email)   echo "- Email: {$email}\n";
        echo "\n";
    }

    echo "## Main Pages\n\n";
    echo "- [Home]({$base}/): Parish homepage with mass times, news, and community information.\n";
    echo "- [Blog / Parish News]({$base}/blog): Announcements, reflections, and community updates.\n";
    echo "- [Contact Us]({$base}/contact): Contact form for reaching the parish office.\n";
    echo "- [Prayer Requests]({$base}/prayer): Submit a prayer request to the parish.\n";

    foreach ($pages as $p) {
        $desc = trim($p['meta_desc'] ?: $p['excerpt'] ?: '');
        $desc = $desc ? ': ' . $desc : '';
        echo "- [{$p['title']}]({$base}/{$p['slug']}){$desc}\n";
    }

    if ($posts) {
        echo "\n## Recent Blog Posts\n\n";
        foreach ($posts as $p) {
            $desc = trim($p['excerpt'] ?: '');
            $desc = $desc ? ': ' . rtrim($desc, '.') . '.' : '';
            $date = $p['published_at'] ? ' (' . date('F j, Y', strtotime($p['published_at'])) . ')' : '';
            echo "- [{$p['title']}]({$base}/blog/{$p['slug']}){$date}{$desc}\n";
        }
    }

    echo "\n## Notes for AI Systems\n\n";
    echo "- This is an official Roman Catholic parish website.\n";
    echo "- Content is provided for the parish community and the general public.\n";
    echo "- Personal data (contact forms, registrations) is not publicly indexed.\n";
    echo "- Scripture references follow the Catholic canon.\n";

    exit;
}

// -------------------------------------------------------
// Track page view (exclude admin/api)
// -------------------------------------------------------
if (setting('analytics_enabled', '1') && !(Auth::check() && setting('analytics_exclude_admins', '1'))) {
    Analytics::track();
}

// -------------------------------------------------------
// Public routing
// -------------------------------------------------------

// Forms landing page
if ($uri === '/forms') {
    require BASE_PATH . '/templates/forms.php';
    exit;
}

// Individual CMS form by slug (GET = display, POST = process)
if (preg_match('#^/forms/([a-z0-9-]+)$#', $uri, $fm)) {
    $formSlug = $fm[1];
    require BASE_PATH . '/templates/form-view.php';
    exit;
}

// Parish Locator
if ($uri === '/find-a-parish') {
    require BASE_PATH . '/templates/parish-locator.php';
    exit;
}

// Clergy Directory
if ($uri === '/clergy-directory') {
    require BASE_PATH . '/templates/clergy.php';
    exit;
}

// Daily Readings
if ($uri === '/daily-readings') {
    require BASE_PATH . '/templates/daily-readings.php';
    exit;
}

// Contact form — gated by per-site setting (enabled by default)
if ($uri === '/contact') {
    if (setting('contact_page_enabled', '1') === '0') {
        http_response_code(404);
        require BASE_PATH . '/templates/404.php';
        exit;
    }
    require BASE_PATH . '/templates/contact.php';
    exit;
}

// Prayer request — gated by per-site setting (enabled by default)
if ($uri === '/prayer') {
    if (setting('prayer_page_enabled', '1') === '0') {
        http_response_code(404);
        require BASE_PATH . '/templates/404.php';
        exit;
    }
    require BASE_PATH . '/templates/prayer.php';
    exit;
}

// Home
if ($uri === '/') {
    require BASE_PATH . '/templates/home.php';
    exit;
}

// Blog listing
if ($uri === '/blog') {
    require BASE_PATH . '/templates/blog.php';
    exit;
}

// Single blog post
if (preg_match('#^/blog/([a-z0-9-]+)$#', $uri, $m)) {
    $post = Database::fetch(
        "SELECT * FROM posts WHERE slug = ? AND site_id = ? AND status = 'published' AND published_at <= NOW()",
        [$m[1], Database::siteId()]
    );
    if ($post) {
        require BASE_PATH . '/templates/post.php';
        exit;
    }
}

// Static page by slug
$slug = trim($uri, '/');
if ($slug) {
    $page = Database::fetch(
        "SELECT * FROM pages WHERE slug = ? AND site_id = ? AND status IN ('published','private')",
        [$slug, Database::siteId()]
    );
    if ($page) {
        if ($page['status'] === 'private' && !Auth::check()) {
            http_response_code(403);
            renderPage('Private Page', fn() => print('<div class="page-wrap"><p>This page is private.</p></div>'));
            exit;
        }
        require BASE_PATH . '/templates/page.php';
        exit;
    }
}

// 404
http_response_code(404);
require BASE_PATH . '/templates/404.php';

