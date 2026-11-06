<?php
/**
 * Secure file upload handling with MIME sniffing, extension whitelists, size limits,
 * image re-encoding for raster images and randomised file names.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Uploader
{
    public const IMAGE = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    public const DOCUMENT = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    public const VIDEO = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    public const ANY = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /**
     * @param array  $file   $_FILES entry
     * @param string $folder Relative upload folder, e.g. products
     * @param array  $allow  Allowed mime map
     * @param bool   $public Store under /uploads (true) or /storage/private (false)
     * @return array{ok:bool, path?:string, absolute?:string, error?:string, mime?:string, size?:int, width?:int, height?:int}
     */
    public static function store(array $file, string $folder, array $allow = self::IMAGE, bool $public = true): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file selected.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => Security::uploadError((int) $file['error'])];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'The uploaded file is empty.'];
        }
        if ($size > MAX_UPLOAD_MB * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File exceeds the ' . MAX_UPLOAD_MB . ' MB limit.'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid upload source.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!isset($allow[$mime])) {
            return ['ok' => false, 'error' => 'Unsupported file type (' . e($mime) . '). Allowed: ' . implode(', ', array_unique(array_values($allow)))];
        }
        // Extra verification for images.
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) {
                return ['ok' => false, 'error' => 'The image file appears to be corrupted.'];
            }
            if ($info[0] > 8000 || $info[1] > 8000) {
                return ['ok' => false, 'error' => 'Image dimensions are too large.'];
            }
        }

        $extension = $allow[$mime];
        $folder = trim(preg_replace('/[^a-z0-9_\/-]/i', '', $folder) ?? 'media', '/');
        $sub = date('Y/m');
        $relativeDir = $folder . '/' . $sub;
        $baseDir = $public ? UPLOAD_PATH : STORAGE_PATH . DIRECTORY_SEPARATOR . 'private';
        $absoluteDir = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return ['ok' => false, 'error' => 'Unable to create the upload directory.'];
        }

        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolute = $absoluteDir . DIRECTORY_SEPARATOR . $name;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $absolute)
            : rename($file['tmp_name'], $absolute);
        if (!$moved) {
            return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
        }
        @chmod($absolute, 0644);

        $relative = $relativeDir . '/' . $name;
        $dimensions = [];
        if (str_starts_with($mime, 'image/')) {
            $dimensions = self::optimiseImage($absolute, $mime);
        }

        if ($public) {
            self::recordMedia($relative, $name, $mime, $size, $folder, $dimensions);
        }

        return [
            'ok' => true,
            'message' => 'Uploaded successfully.',
            'path' => $public ? $relative : 'private:' . $relative,
            'absolute' => $absolute,
            'mime' => $mime,
            'size' => $size,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
        ];
    }

    /** Re-encode large raster images to keep the site fast, and strip EXIF metadata. */
    private static function optimiseImage(string $absolute, string $mime): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            $info = @getimagesize($absolute);
            return ['width' => $info[0] ?? null, 'height' => $info[1] ?? null];
        }
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absolute),
            'image/png' => @imagecreatefrompng($absolute),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            'image/gif' => @imagecreatefromgif($absolute),
            default => false,
        };
        if (!$source) {
            $info = @getimagesize($absolute);
            return ['width' => $info[0] ?? null, 'height' => $info[1] ?? null];
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $maxWidth = 1920;
        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * ($maxWidth / $width));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }
        match ($mime) {
            'image/jpeg' => imagejpeg($source, $absolute, 82),
            'image/png' => imagepng($source, $absolute, 8),
            'image/webp' => function_exists('imagewebp') ? imagewebp($source, $absolute, 82) : null,
            'image/gif' => imagegif($source, $absolute),
            default => null,
        };
        imagedestroy($source);
        return ['width' => $width, 'height' => $height];
    }

    private static function recordMedia(string $relative, string $name, string $mime, int $size, string $folder, array $dimensions): void
    {
        try {
            $db = Database::instance();
            if (!$db->tableExists('media_library')) {
                return;
            }
            $db->insert('media_library', [
                'file_name' => $name,
                'file_path' => 'uploads/' . $relative,
                'file_type' => str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document'),
                'mime_type' => $mime,
                'file_size' => $size,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
                'folder' => $folder,
                'uploaded_by' => AdminAuth::id(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('media record failed: ' . $e->getMessage());
        }
    }

    /** Store a base64 data URL (used by the rich text editor image drag-drop). */
    public static function storeBase64(string $dataUrl, string $folder = 'media'): ?string
    {
        if (!preg_match('#^data:(image/(?:png|jpe?g|webp|gif));base64,(.+)$#', $dataUrl, $m)) {
            return null;
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false || strlen($binary) > MAX_UPLOAD_MB * 1024 * 1024) {
            return null;
        }
        $ext = $m[1] === 'image/jpeg' ? 'jpg' : str_replace('image/', '', $m[1]);
        $dir = UPLOAD_PATH . '/' . $folder . '/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $binary) === false) {
            return null;
        }
        return $folder . '/' . date('Y/m') . '/' . $name;
    }

    public static function delete(?string $relativePath, bool $public = true): bool
    {
        if (!$relativePath) {
            return false;
        }
        $relativePath = str_replace('private:', '', $relativePath);
        $base = $public ? UPLOAD_PATH : STORAGE_PATH . DIRECTORY_SEPARATOR . 'private';
        $absolute = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/')));
        $baseReal = realpath($base);
        if ($absolute === false || $baseReal === false || !str_starts_with($absolute, $baseReal)) {
            return false;
        }
        return is_file($absolute) && @unlink($absolute);
    }
}
