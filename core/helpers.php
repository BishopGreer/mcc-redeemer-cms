<?php

function cspNonce(): string {
    static $nonce = null;
    if ($nonce === null) $nonce = base64_encode(random_bytes(16));
    return $nonce;
}

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = preg_replace('/[^\w\s-]/', '', $str);
    $str = preg_replace('/[\s_-]+/', '-', $str);
    return trim($str, '-');
}

function uniqueSlug(string $base, string $table, int $excludeId = 0): string {
    $slug = slugify($base);
    $orig = $slug;
    $i    = 1;
    $sid  = Database::siteId();
    while (true) {
        $row = Database::fetch(
            "SELECT id FROM `$table` WHERE site_id = ? AND slug = ? AND id != ?",
            [$sid, $slug, $excludeId]
        );
        if (!$row) break;
        $slug = $orig . '-' . $i++;
    }
    return $slug;
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flash(string $key, string $message = ''): ?string {
    if ($message !== '') {
        // Writing flash: need a writable session. If Auth::init() was called with
        // read_and_close = true, session_status() is PHP_SESSION_NONE here — restart it.
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    if ($msg !== null) {
        unset($_SESSION['flash'][$key]);
    }
    return $msg;
}

function formatDate(string $datetime, string $format = ''): string {
    if (!$format) $format = Database::setting('date_format', 'F j, Y');
    return date($format, strtotime($datetime));
}

function excerpt(string $content, int $words = 30): string {
    $text = strip_tags($content);
    $arr  = explode(' ', $text);
    if (count($arr) <= $words) return $text;
    return implode(' ', array_slice($arr, 0, $words)) . '&hellip;';
}

function siteUrl(string $path = ''): string {
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

function adminUrl(string $path = ''): string {
    return siteUrl('admin/' . ltrim($path, '/'));
}

// URL for the network's main domain (used in network admin links).
function networkUrl(string $path = ''): string {
    if (defined('NETWORK_MODE') && NETWORK_MODE && defined('NETWORK_BASE_DOMAIN') && NETWORK_BASE_DOMAIN !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . NETWORK_BASE_DOMAIN;
    } else {
        $base = SITE_URL;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// URL for a specific subsite (by subdomain string).
function subsiteUrl(string $subdomain, string $path = ''): string {
    if (defined('NETWORK_MODE') && NETWORK_MODE && defined('NETWORK_BASE_DOMAIN') && NETWORK_BASE_DOMAIN !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $subdomain !== '' ? $subdomain . '.' . NETWORK_BASE_DOMAIN : NETWORK_BASE_DOMAIN;
        $base   = $scheme . '://' . $host;
    } else {
        $base = SITE_URL;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function isCurrentPage(string $url): bool {
    $request = rtrim(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), '/');
    $target  = rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
    return $request === $target;
}

function json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): never {
    json(['error' => $message], $status);
}

function truncate(string $str, int $len = 80): string {
    return mb_strlen($str) > $len ? mb_substr($str, 0, $len - 1) . '&hellip;' : $str;
}

function mediaUrl(int $id, bool $thumb = false): string {
    $m = Database::fetch("SELECT * FROM media WHERE id = ?", [$id]);
    return $m ? Media::url($m, $thumb) : '';
}

function setting(string $key, string $default = ''): string {
    return Database::setting($key, $default);
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . h(Auth::csrf()) . '">';
}

/**
 * Process shortcodes embedded in page content.
 * Currently supports: [daily-readings]  [child-pages]  [blog-posts]
 *
 * @param array $context  Optional runtime context, e.g. ['page_id' => 42]
 */
function processShortcodes(string $content, array $context = []): string
{
    // [daily-readings] — embed today's readings inline
    if (str_contains($content, '[daily-readings]')) {
        $replacement = renderDailyReadingsShortcode();
        $content = str_replace('[daily-readings]', $replacement, $content);
    }

    // [child-pages] / [child-pages style="list"|"cards" columns="2|3"]
    if (str_contains($content, '[child-pages')) {
        $content = preg_replace_callback(
            '/\[child-pages([^\]]*)\]/',
            function (array $m) use ($context) {
                $attrs = [];
                preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $m[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) $attrs[$p[1]] = $p[2];
                return renderChildPagesShortcode(
                    (int)($context['page_id'] ?? 0),
                    $attrs['style']   ?? 'cards',
                    (int)($attrs['columns'] ?? 3)
                );
            },
            $content
        );
    }

    // [blog-posts] / [blog-posts style="excerpts|full|list" count="5" category="slug" columns="2|3"]
    if (str_contains($content, '[blog-posts')) {
        $content = preg_replace_callback(
            '/\[blog-posts([^\]]*)\]/',
            function (array $m) {
                $attrs = [];
                preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $m[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) $attrs[$p[1]] = $p[2];
                return renderBlogPostsShortcode(
                    $attrs['style']    ?? 'excerpts',
                    (int)($attrs['count']   ?? 5),
                    $attrs['category'] ?? '',
                    (int)($attrs['columns'] ?? 3)
                );
            },
            $content
        );
    }

    return $content;
}

/**
 * Render [blog-posts] shortcode output.
 *
 * @param string $style    'excerpts' | 'full' | 'list'
 * @param int    $count    Number of posts to show (max 50)
 * @param string $category Category slug to filter by (empty = all)
 * @param int    $columns  Grid columns for excerpts style (1–4)
 */
function renderBlogPostsShortcode(
    string $style    = 'excerpts',
    int    $count    = 5,
    string $category = '',
    int    $columns  = 3
): string {
    $count   = max(1, min(50, $count));
    $columns = max(1, min(4, $columns));

    $where  = "p.status = 'published' AND p.published_at <= NOW() AND p.site_id = ?";
    $params = [Database::siteId()];

    if ($category !== '') {
        $where  .= " AND c.slug = ?";
        $params[] = $category;
    }

    $posts = Database::fetchAll(
        "SELECT p.*, u.name AS author_name, c.name AS category_name, c.slug AS category_slug
         FROM posts p
         LEFT JOIN users u ON u.id = p.author_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE $where
         ORDER BY p.published_at DESC, p.created_at DESC
         LIMIT $count",
        $params
    );

    if (!$posts) return '';

    ob_start();

    if ($style === 'list') {
        echo '<ul class="bps-list">';
        foreach ($posts as $post) {
            $href = siteUrl('blog/' . $post['slug']);
            $date = formatDate($post['published_at'] ?: $post['created_at'], 'F j, Y');
            echo '<li class="bps-list-item">';
            echo '<a href="' . h($href) . '" class="bps-list-title">' . h($post['title']) . '</a>';
            echo '<span class="bps-list-meta">' . h($date) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

    } elseif ($style === 'full') {
        foreach ($posts as $post) {
            $href  = siteUrl('blog/' . $post['slug']);
            $date  = formatDate($post['published_at'] ?: $post['created_at'], 'F j, Y');
            $thumb = $post['featured_image'] ? mediaUrl($post['featured_image']) : null;
            echo '<article class="bps-full-post">';
            if ($thumb) echo '<img src="' . h($thumb) . '" class="bps-full-img" alt="' . h($post['title']) . '">';
            echo '<header class="bps-full-header">';
            echo '<h2 class="bps-full-title"><a href="' . h($href) . '">' . h($post['title']) . '</a></h2>';
            echo '<div class="bps-full-meta">' . h($date);
            if ($post['author_name']) echo ' &mdash; ' . h($post['author_name']);
            if ($post['category_name']) echo ' &mdash; <a href="' . h(siteUrl('blog?category=' . $post['category_slug'])) . '">' . h($post['category_name']) . '</a>';
            echo '</div>';
            echo '</header>';
            echo '<div class="bps-full-content entry-content">' . ($post['content'] ?? '') . '</div>';
            echo '<div class="bps-full-footer"><a href="' . h($href) . '" class="btn-outline">Permalink</a></div>';
            echo '</article>';
        }

    } else {
        // excerpts (card grid)
        echo '<div class="bps-grid bps-cols-' . $columns . '">';
        foreach ($posts as $post) {
            $href    = siteUrl('blog/' . $post['slug']);
            $date    = formatDate($post['published_at'] ?: $post['created_at'], 'F j, Y');
            $thumb   = $post['featured_image'] ? mediaUrl($post['featured_image'], true) : null;
            $excerpt = $post['excerpt'] ?: excerpt($post['content'] ?? '', 25);
            echo '<div class="bps-card">';
            if ($thumb) echo '<a href="' . h($href) . '"><img src="' . h($thumb) . '" class="bps-card-img" alt="' . h($post['title']) . '"></a>';
            echo '<div class="bps-card-body">';
            echo '<div class="bps-card-meta">' . h($date);
            if ($post['category_name']) echo ' &bull; <a href="' . h(siteUrl('blog?category=' . $post['category_slug'])) . '">' . h($post['category_name']) . '</a>';
            echo '</div>';
            echo '<h3 class="bps-card-title"><a href="' . h($href) . '">' . h($post['title']) . '</a></h3>';
            if ($excerpt) echo '<p class="bps-card-excerpt">' . h($excerpt) . '</p>';
            echo '<a href="' . h($href) . '" class="bps-read-more">Read More &rarr;</a>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    return ob_get_clean();
}

/**
 * Render [child-pages] shortcode output.
 */
function renderChildPagesShortcode(int $pageId, string $style = 'cards', int $columns = 3): string
{
    if (!$pageId) return '';

    $children = Database::fetchAll(
        "SELECT id, title, slug, excerpt, content, featured_image, nav_label
         FROM pages
         WHERE parent_id = ? AND site_id = ? AND status = 'published'
         ORDER BY menu_order ASC, title ASC",
        [$pageId, Database::siteId()]
    );

    if (!$children) return '';

    ob_start();

    if ($style === 'list') {
        echo '<ul class="scp-list">';
        foreach ($children as $ch) {
            $label = $ch['nav_label'] ?: $ch['title'];
            $href  = siteUrl($ch['slug']);
            echo '<li><a href="' . h($href) . '">' . h($label) . '</a></li>';
        }
        echo '</ul>';
    } else {
        // Cards layout
        $cols = max(1, min(4, $columns));
        echo '<div class="scp-grid scp-cols-' . $cols . '">';
        foreach ($children as $ch) {
            $href    = siteUrl($ch['slug']);
            $label   = $ch['nav_label'] ?: $ch['title'];
            $excerpt = $ch['excerpt'] ?: excerpt($ch['content'] ?? '', 25);
            $thumb   = $ch['featured_image'] ? mediaUrl($ch['featured_image'], true) : null;
            echo '<a href="' . h($href) . '" class="scp-card">';
            if ($thumb) {
                echo '<div class="scp-card-img"><img src="' . h($thumb) . '" alt="' . h($label) . '"></div>';
            }
            echo '<div class="scp-card-body">';
            echo '<h3 class="scp-card-title">' . h($label) . '</h3>';
            if ($excerpt) echo '<p class="scp-card-excerpt">' . h($excerpt) . '</p>';
            echo '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    return ob_get_clean();
}

/**
 * Render the [daily-readings] shortcode output.
 * Returns an HTML string.
 */
function renderDailyReadingsShortcode(): string
{
    if (!class_exists('Lectionary')) {
        @require_once BASE_PATH . '/core/Lectionary.php';
    }

    $today   = new DateTimeImmutable('today');
    $info    = Lectionary::liturgicalDay($today);
    $readRow = Lectionary::readingsForDate($today);

    $seasonColor = match ($info['season']) {
        'advent'    => '#4a0072',
        'lent'      => '#6a0e0e',
        'holyweek'  => '#6a0e0e',
        'easter'    => '#c8a400',
        'christmas' => '#b71c1c',
        default     => '#5d4037',
    };

    // Text priority: manually pasted text → auto-fetch via bible-api.com
    $getText = fn(?string $override, ?string $ref): ?string =>
        ($override && trim($override)) ? trim($override)
        : (($ref && trim($ref)) ? Lectionary::fetchPassage(trim($ref)) : null);

    ob_start();
    ?>
    <div class="daily-readings-shortcode" style="border:1px solid #e0d6cc; border-radius:8px; overflow:hidden; margin:24px 0;">

      <div style="background:<?= $seasonColor ?>; color:#fff; padding:12px 20px; text-align:center;">
        <div style="font-size:12px; text-transform:uppercase; letter-spacing:.07em; opacity:.85;">
          <?= h(date('l, F j, Y', $today->getTimestamp())) ?>
        </div>
        <div style="font-size:19px; font-weight:700; margin-top:2px;">
          <?= h($readRow['liturgical_title'] ?? $info['title']) ?>
        </div>
      </div>

      <div style="padding:20px 24px;">
      <?php if (!$readRow): ?>
        <p style="color:#888; font-style:italic; text-align:center; margin:0;">
          Readings for today have not been entered yet.
          See the <a href="<?= siteUrl('daily-readings') ?>">Daily Readings page</a> for details.
        </p>
      <?php else: ?>

        <?php if (!empty($readRow['notes'])): ?>
        <p style="font-style:italic; color:#666; border-left:3px solid <?= $seasonColor ?>;
                  padding-left:12px; margin-bottom:18px; font-size:14px;">
          <?= nl2br(h($readRow['notes'])) ?>
        </p>
        <?php endif; ?>

        <?php
        $readings = [
            ['label' => 'First Reading',      'ref' => $readRow['reading1_ref'], 'text' => $readRow['reading1_text'] ?? null],
            ['label' => 'Responsorial Psalm', 'ref' => $readRow['psalm_ref'],    'text' => $readRow['psalm_text'] ?? null,    'psalm' => true],
            ['label' => 'Second Reading',     'ref' => $readRow['reading2_ref'], 'text' => $readRow['reading2_text'] ?? null],
            ['label' => 'Gospel',             'ref' => $readRow['gospel_ref'],   'text' => $readRow['gospel_text'] ?? null,   'gospel' => true],
        ];

        foreach ($readings as $rd):
            if (!$rd['ref'] && !$rd['text']) continue;
            $passageText = $getText($rd['text'], $rd['ref']);
        ?>
        <div style="margin-bottom:20px;">
          <h4 style="font-size:13px; text-transform:uppercase; letter-spacing:.05em;
                     color:<?= $seasonColor ?>; margin:0 0 4px;">
            <?= h($rd['label']) ?>
            <?php if ($rd['ref']): ?>
            <span style="font-weight:400; font-style:italic; text-transform:none;
                         letter-spacing:0; color:#888; font-size:12px;">
              &mdash; <?= h($rd['ref']) ?>
            </span>
            <?php endif; ?>
          </h4>
          <?php if ($passageText): ?>
            <div style="font-size:15px; line-height:1.75; color:#333;
                 <?= !empty($rd['psalm'])  ? 'font-style:italic;' : '' ?>
                 <?= !empty($rd['gospel']) ? 'border-left:3px solid ' . $seasonColor . '; padding-left:14px;' : '' ?>">
              <?= nl2br(h($passageText)) ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <p style="text-align:right; margin:8px 0 0; font-size:12px; color:#aaa;">
          CPDV &bull;
          <a href="<?= siteUrl('daily-readings') ?>" style="color:<?= $seasonColor ?>;">
            Full Readings Page &rarr;
          </a>
        </p>

      <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

function pagination(int $total, int $page, int $perPage, string $baseUrl): string {
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<nav class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $sep    = str_contains($baseUrl, '?') ? '&' : '?';
        $html  .= '<a href="' . $baseUrl . $sep . 'page=' . $i . '" class="page-link' . $active . '">' . $i . '</a>';
    }
    $html .= '</nav>';
    return $html;
}
