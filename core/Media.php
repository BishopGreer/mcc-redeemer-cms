<?php
class Media {

    public static function upload(array $file, int $uploaderId, int $siteId = 1): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code: ' . $file['error']);
        }

        if ($file['size'] > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('File exceeds maximum upload size.');
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('File type not allowed: ' . $mimeType);
        }

        $ext      = self::extensionForMime($mimeType);
        $filename = uniqid('osf_', true) . '.' . $ext;
        $subdir   = date('Y/m');
        $dir      = UPLOAD_DIR . '/images/' . $subdir;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $destPath = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }

        // Fix EXIF orientation before anything else so thumbnails and
        // stored dimensions reflect the corrected image.
        if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
            self::fixOrientation($destPath);
        }

        $width = $height = null;
        $thumbPath = null;

        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            [$width, $height] = getimagesize($destPath) ?: [null, null];
            $thumbPath = self::createThumbnail($destPath, $subdir, $filename, $mimeType);
        }

        $relativePath  = 'images/' . $subdir . '/' . $filename;
        $relativeThumb = $thumbPath ? 'images/' . $subdir . '/thumb_' . $filename : null;

        $id = Database::insert('media', [
            'site_id'       => $siteId,
            'filename'      => $filename,
            'original_name' => basename($file['name']),
            'mime_type'     => $mimeType,
            'file_size'     => $file['size'],
            'width'         => $width,
            'height'        => $height,
            'path'          => $relativePath,
            'thumb_path'    => $relativeThumb,
            'uploader_id'   => $uploaderId,
        ]);

        return Database::fetch("SELECT * FROM media WHERE id = ?", [$id]);
    }

    public static function url(array $media, bool $thumb = false): string {
        $path = $thumb && $media['thumb_path'] ? $media['thumb_path'] : $media['path'];
        return UPLOAD_URL . '/' . $path;
    }

    public static function delete(int $id): void {
        $media = Database::fetch("SELECT * FROM media WHERE id = ?", [$id]);
        if (!$media) return;

        $base = UPLOAD_DIR . '/';
        if ($media['path'] && file_exists($base . $media['path'])) {
            unlink($base . $media['path']);
        }
        if ($media['thumb_path'] && file_exists($base . $media['thumb_path'])) {
            unlink($base . $media['thumb_path']);
        }

        Database::delete('media', 'id = ?', [$id]);
    }

    private static function createThumbnail(string $srcPath, string $subdir, string $filename, string $mime): ?string {
        if (!function_exists('imagecreatefromjpeg')) return null;

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($srcPath),
            'image/png'  => @imagecreatefrompng($srcPath),
            'image/gif'  => @imagecreatefromgif($srcPath),
            'image/webp' => @imagecreatefromwebp($srcPath),
            default      => null,
        };

        if (!$src) return null;

        $w = imagesx($src);
        $h = imagesy($src);

        $tw = THUMB_WIDTH;
        $th = THUMB_HEIGHT;

        $ratio = min($tw / $w, $th / $h);
        $nw    = (int) round($w * $ratio);
        $nh    = (int) round($h * $ratio);

        $dst = imagecreatetruecolor($tw, $th);

        // White background for transparent images
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);

        $ox = (int) round(($tw - $nw) / 2);
        $oy = (int) round(($th - $nh) / 2);

        imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $nw, $nh, $w, $h);

        $thumbFile = UPLOAD_DIR . '/images/' . $subdir . '/thumb_' . $filename;
        imagejpeg($dst, $thumbFile, 85);

        imagedestroy($src);
        imagedestroy($dst);

        return 'images/' . $subdir . '/thumb_' . $filename;
    }

    private static function fixOrientation(string $path): void {
        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 1;
        if ($orientation === 1) return; // already upright

        $img = @imagecreatefromjpeg($path);
        if (!$img) return;

        $rotated = match ((int)$orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),   // 90° CW shot
            8 => imagerotate($img,  90, 0),   // 90° CCW shot
            default => null,
        };

        if ($rotated) {
            imagejpeg($rotated, $path, 95);
            imagedestroy($rotated);
        }
        imagedestroy($img);
    }

    private static function extensionForMime(string $mime): string {
        return match ($mime) {
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'image/svg+xml'    => 'svg',
            'application/pdf'  => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain'       => 'txt',
            default            => 'bin',
        };
    }
}
