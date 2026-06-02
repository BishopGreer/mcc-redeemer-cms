<?php
/**
 * AJAX: Share a blog post to one or more social platforms.
 * POST params: _csrf, post_id, platforms[] (array), message (optional custom text)
 * Returns JSON: { results: { facebook: {success, error}, ... } }
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Social.php';

header('Content-Type: application/json');

Auth::init();

if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

Auth::verifyCsrf();

$postId    = (int)($_POST['post_id'] ?? 0);
$platforms = (array)($_POST['platforms'] ?? []);
$message   = trim($_POST['message'] ?? '');

if (!$postId) {
    echo json_encode(['error' => 'No post specified.']);
    exit;
}

$post = Database::fetch("SELECT * FROM posts WHERE id = ? AND status = 'published' AND site_id = ?", [$postId, Database::siteId()]);
if (!$post) {
    echo json_encode(['error' => 'Post not found or not published.']);
    exit;
}

$postUrl = siteUrl('blog/' . $post['slug']);
$text    = $message ?: Social::defaultMessage($post, $postUrl);

$allowed   = ['facebook', 'bluesky', 'threads', 'mastodon'];
$platforms = array_filter($platforms, fn($p) => in_array($p, $allowed));

if (empty($platforms)) {
    echo json_encode(['error' => 'No valid platforms selected.']);
    exit;
}

$results = [];

foreach ($platforms as $platform) {
    $res = match ($platform) {
        'facebook' => Social::shareToFacebook(
            $text,
            $postUrl,
            setting('social_fb_page_id', ''),
            setting('social_fb_access_token', '')
        ),
        'bluesky' => Social::shareToBlueSky(
            $text,
            setting('social_bsky_handle', ''),
            setting('social_bsky_app_password', '')
        ),
        'threads' => Social::shareToThreads(
            $text,
            setting('social_threads_user_id', ''),
            setting('social_threads_access_token', '')
        ),
        'mastodon' => Social::shareToMastodon(
            $text,
            setting('social_mastodon_instance', ''),
            setting('social_mastodon_token', '')
        ),
        default => ['success' => false, 'post_id' => null, 'error' => 'Unknown platform.'],
    };

    // Record in social_shares
    Database::insert('social_shares', [
        'post_id'          => $postId,
        'platform'         => $platform,
        'status'           => $res['success'] ? 'success' : 'failed',
        'platform_post_id' => $res['post_id'] ?? null,
        'message'          => mb_substr($text, 0, 1000, 'UTF-8'),
        'error_message'    => $res['error'] ?? null,
        'shared_by'        => Auth::id(),
    ]);

    $results[$platform] = [
        'success'  => $res['success'],
        'post_id'  => $res['post_id'] ?? null,
        'error'    => $res['error'] ?? null,
    ];
}

echo json_encode(['results' => $results]);
