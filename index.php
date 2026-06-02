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
        $section === 'forms'          => require BASE_PATH . '/admin/forms.php',
        $section === 'contacts'       => require BASE_PATH . '/admin/contacts.php',
        $section === 'contact-prayer' => require BASE_PATH . '/admin/contact-prayer.php',
        $section === 'categories'     => require BASE_PATH . '/admin/categories.php',
        $section === 'tags'           => require BASE_PATH . '/admin/tags.php',
        $section === 'board' && ($subact === 'edit' || $adminPath === 'board/new')
                                      => require BASE_PATH . '/admin/board-edit.php',
        $section === 'board'          => require BASE_PATH . '/admin/board.php',
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

    $blogEnabled = setting('blog_enabled', '0') === '1';
    $staticUrls = array_filter([
        ['loc' => $base . '/',      'changefreq' => 'weekly',  'priority' => '1.0'],
        ['loc' => $base . '/board', 'changefreq' => 'monthly', 'priority' => '0.7'],
        setting('contact_page_enabled', '1') !== '0'
            ? ['loc' => $base . '/contact', 'changefreq' => 'monthly', 'priority' => '0.5']
            : null,
        $blogEnabled
            ? ['loc' => $base . '/blog', 'changefreq' => 'daily', 'priority' => '0.9']
            : null,
    ]);

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
    echo "{$siteName} is an inclusive Christian church in Augusta, Georgia, part of Metropolitan Community Churches. ";
    echo "This file helps AI systems understand our site's structure and content.\n\n";

    if ($address || $phone || $email) {
        echo "## Contact\n\n";
        if ($address) echo "- Address: {$address}\n";
        if ($phone)   echo "- Phone: {$phone}\n";
        if ($email)   echo "- Email: {$email}\n";
        echo "\n";
    }

    echo "## Main Pages\n\n";
    echo "- [Home]({$base}/): Church homepage with service times, news, and community information.\n";
    echo "- [Board of Directors]({$base}/board): Our church leadership and board members.\n";
    echo "- [Contact Us]({$base}/contact): Contact form for reaching the church office.\n";

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
    echo "- This is the official website of MCC Our Redeemer, Augusta, GA.\n";
    echo "- MCC (Metropolitan Community Churches) is an inclusive, affirming Christian denomination.\n";
    echo "- Content is provided for the church community and the general public.\n";
    echo "- Personal data (contact forms) is not publicly indexed.\n";

    exit;
}

// -------------------------------------------------------
// Analytics API — record page duration via JS beacon
// -------------------------------------------------------
if ($uri === '/api/analytics-ping' && $method === 'POST') {
    require_once BASE_PATH . '/core/Analytics.php';
    $sessionId = preg_replace('/[^a-f0-9]/', '', $_POST['sid'] ?? '');
    $url       = substr($_POST['url'] ?? '', 0, 500);
    $dur       = (int)($_POST['dur'] ?? 0);
    if ($sessionId && $url && $dur > 0) {
        Analytics::recordDuration($sessionId, $url, $dur);
    }
    http_response_code(204);
    exit;
}

// Constant Contact newsletter signup
if ($uri === '/api/cc-signup') {
    require BASE_PATH . '/api/cc-signup.php';
    exit;
}

// -------------------------------------------------------
// Track page view — analytics (server-side, no outside services)
// -------------------------------------------------------
require_once BASE_PATH . '/core/Analytics.php';
Analytics::track();

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

// Board of Directors / Leadership
if ($uri === '/board' || $uri === '/board-of-directors') {
    require BASE_PATH . '/templates/board.php';
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

// Home
if ($uri === '/') {
    require BASE_PATH . '/templates/home.php';
    exit;
}

// Blog — gated by blog_enabled setting
if ($uri === '/blog') {
    if (setting('blog_enabled', '0') === '0') {
        http_response_code(404);
        require BASE_PATH . '/templates/404.php';
        exit;
    }
    require BASE_PATH . '/templates/blog.php';
    exit;
}

// Single blog post — also gated
if (preg_match('#^/blog/([a-z0-9-]+)$#', $uri, $m)) {
    if (setting('blog_enabled', '0') === '0') {
        http_response_code(404);
        require BASE_PATH . '/templates/404.php';
        exit;
    }
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

