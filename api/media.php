<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Media.php';
require_once BASE_PATH . '/core/helpers.php';

Auth::init();
if (!Auth::check()) { jsonError('Unauthorized', 401); }

$action = $_GET['action'] ?? basename($_SERVER['PHP_SELF'], '.php');

// POST /api/media/upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    // Accept both standard 'file' uploads and Jodit's default 'files[0]' format
    $fileData = $_FILES['file'] ?? null;
    if (!$fileData && !empty($_FILES['files'])) {
        $f = $_FILES['files'];
        $fileData = [
            'name'     => is_array($f['name'])     ? $f['name'][0]     : $f['name'],
            'type'     => is_array($f['type'])     ? $f['type'][0]     : $f['type'],
            'tmp_name' => is_array($f['tmp_name']) ? $f['tmp_name'][0] : $f['tmp_name'],
            'error'    => is_array($f['error'])    ? $f['error'][0]    : $f['error'],
            'size'     => is_array($f['size'])     ? $f['size'][0]     : $f['size'],
        ];
    }
    if (!$fileData) jsonError('No file uploaded.');

    try {
        $media = Media::upload($fileData, Auth::id(), Database::siteId());
        json([
            'id'            => $media['id'],
            'original_name' => $media['original_name'],
            'mime_type'     => $media['mime_type'],
            'url'           => Media::url($media),
            'thumb_url'     => $media['thumb_path'] ? Media::url($media, true) : null,
            'width'         => $media['width'],
            'height'        => $media['height'],
            'location'      => Media::url($media), // kept for any legacy references
        ]);
    } catch (RuntimeException $e) {
        jsonError($e->getMessage());
    }
}

// GET /api/media/list
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q    = trim($_GET['q'] ?? '');
    $type = $_GET['type'] ?? '';

    $where  = "WHERE site_id = ?";
    $params = [Database::siteId()];
    if ($q)              { $where .= " AND original_name LIKE ?"; $params[] = "%$q%"; }
    if ($type === 'image') { $where .= " AND mime_type LIKE 'image/%'"; }

    $items = Database::fetchAll(
        "SELECT * FROM media $where ORDER BY created_at DESC LIMIT 200",
        $params
    );

    json(array_map(function($m) {
        return [
            'id'            => $m['id'],
            'original_name' => $m['original_name'],
            'mime_type'     => $m['mime_type'],
            'file_size'     => $m['file_size'],
            'width'         => $m['width'],
            'height'        => $m['height'],
            'alt_text'      => $m['alt_text'] ?? '',
            'url'           => Media::url($m),
            'thumb_url'     => $m['thumb_path'] ? Media::url($m, true) : null,
        ];
    }, $items));
}
