<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/helpers.php';

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

header('Content-Type: application/json');

$post = Database::fetch(
    "SELECT * FROM posts WHERE status = 'published' AND site_id = ?
     ORDER BY published_at DESC, id DESC LIMIT 1",
    [Database::siteId()]
);

if (!$post) {
    echo json_encode(['error' => 'No published posts found.']);
    exit;
}

$excerpt = $post['excerpt']
    ? $post['excerpt']
    : (mb_strlen(strip_tags($post['content'])) > 200
        ? mb_substr(strip_tags($post['content']), 0, 200) . '…'
        : strip_tags($post['content']));

// Strip script/style tags from full content for safe email insertion
$fullContent = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $post['content']);

$imageUrl = null;
if (!empty($post['featured_image'])) {
    $media = Database::fetch("SELECT * FROM media WHERE id = ? AND site_id = ?", [(int)$post['featured_image'], Database::siteId()]);
    if ($media) {
        $imageUrl = Media::url($media);
    }
}

echo json_encode([
    'title'        => $post['title'],
    'excerpt'      => $excerpt,
    'full_content' => $fullContent,
    'url'          => rtrim(SITE_URL, '/') . '/blog/' . $post['slug'],
    'image_url'    => $imageUrl,
]);
