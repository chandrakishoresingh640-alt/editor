<?php
declare(strict_types=1);

final class Storage
{
    public static function owner(): string
    {
        if (empty($_SESSION['owner'])) {
            $_SESSION['owner'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['owner'];
    }

    public static function folder(): string
    {
        $dir = APP_ROOT . '/private_storage/uploads/' . self::owner();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new AppException('Uploaded files cannot be stored on this server.', 500, 'storage');
        }
        return $dir;
    }

    public static function put(string $bytes, string $originalName, string $kind): array
    {
        $limit = effective_upload_limit();
        if (strlen($bytes) < 1) {
            throw new AppException('The file is empty.', 422, 'empty');
        }
        if (strlen($bytes) > $limit) {
            throw new AppException('That file is larger than the ' . self::formatBytes($limit) . ' limit.', 413, 'too_large');
        }
        $detected = self::detect($bytes, $kind);
        $id = bin2hex(random_bytes(16));
        $dir = self::folder();
        $bin = $dir . '/' . $id . '.bin';
        if (file_put_contents($bin, $bytes) === false) {
            throw new AppException('The file could not be stored.', 500, 'storage');
        }
        chmod($bin, 0640);
        $meta = [
            'id' => $id,
            'owner' => self::owner(),
            'name' => self::safeName($originalName, $detected['ext']),
            'mime' => $detected['mime'],
            'ext' => $detected['ext'],
            'kind' => $detected['kind'],
            'size' => strlen($bytes),
            'created' => time(),
        ];
        if ($detected['kind'] === 'pdf') {
            $pages = self::pageCount($bin);
            $meta['pages'] = $pages;
            $max = (int) config('max_pdf_pages');
            if ($pages !== null && $pages > $max) {
                @unlink($bin);
                throw new AppException('This PDF has ' . $pages . ' pages. The limit on this server is ' . $max . '.', 413, 'too_many_pages');
            }
        }
        self::writeMeta($meta);
        return $meta;
    }

    public static function meta(string $id): array
    {
        $id = self::assertId($id);
        $path = self::folder() . '/' . $id . '.json';
        if (!is_file($path)) {
            throw new AppException('That file is no longer available. Upload it again.', 404, 'not_found');
        }
        $meta = json_decode((string) file_get_contents($path), true);
        if (!is_array($meta) || !hash_equals(self::owner(), (string) ($meta['owner'] ?? ''))) {
            throw new AppException('That file is no longer available. Upload it again.', 404, 'not_found');
        }
        return $meta;
    }

    public static function bytes(string $id): string
    {
        $meta = self::meta($id);
        $path = self::folder() . '/' . $meta['id'] . '.bin';
        $data = file_get_contents($path);
        if ($data === false) {
            throw new AppException('That file is no longer available. Upload it again.', 404, 'not_found');
        }
        return $data;
    }

    public static function path(string $id): string
    {
        $meta = self::meta($id);
        return self::folder() . '/' . $meta['id'] . '.bin';
    }

    public static function delete(string $id): void
    {
        $meta = self::meta($id);
        @unlink(self::folder() . '/' . $meta['id'] . '.bin');
        @unlink(self::folder() . '/' . $meta['id'] . '.json');
    }

    public static function send(string $id, ?string $downloadName = null): never
    {
        $meta = self::meta($id);
        $path = self::folder() . '/' . $meta['id'] . '.bin';
        $name = self::safeName($downloadName ?: (string) $meta['name'], (string) $meta['ext']);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: ('download.' . $meta['ext']);
        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public static function assertId(string $id): string
    {
        $id = strtolower($id);
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new AppException('The file reference is not valid.', 400, 'bad_id');
        }
        return $id;
    }

    public static function detect(string $bytes, string $expectedKind): array
    {
        $head = substr($bytes, 0, 16);
        $kind = null;
        $mime = null;
        $ext = null;
        if (str_starts_with($bytes, '%PDF-')) {
            $kind = 'pdf';
            $mime = 'application/pdf';
            $ext = 'pdf';
        } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
            $kind = 'image';
            $mime = 'image/jpeg';
            $ext = 'jpg';
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            $kind = 'image';
            $mime = 'image/png';
            $ext = 'png';
        } elseif (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            $kind = 'image';
            $mime = 'image/gif';
            $ext = 'gif';
        } elseif (str_starts_with($head, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            $kind = 'image';
            $mime = 'image/webp';
            $ext = 'webp';
        }
        if ($kind === null) {
            throw new AppException('This file type is not supported. Use a PDF, JPG, PNG, GIF, or WebP file.', 415, 'type');
        }
        if ($expectedKind === 'pdf' && $kind !== 'pdf') {
            throw new AppException('Upload a PDF file.', 415, 'type');
        }
        if ($expectedKind === 'image' && $kind !== 'image') {
            throw new AppException('Upload a JPG, PNG, GIF, or WebP image.', 415, 'type');
        }
        if ($mime === 'image/webp' && !self::webpEnabled()) {
            throw new AppException('This server’s image library cannot read WebP. Use JPG or PNG.', 415, 'type');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $reported = finfo_buffer($finfo, $bytes) ?: '';
                finfo_close($finfo);
                $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/octet-stream'];
                if ($reported !== '' && !in_array($reported, $allowed, true) && !str_starts_with($reported, 'image/')) {
                    throw new AppException('The file contents do not match a supported document or image.', 415, 'type');
                }
            }
        }
        return ['kind' => $kind, 'mime' => $mime, 'ext' => $ext];
    }

    public static function safeName(string $name, string $ext): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.' . $ext;
        }
        if (strlen($name) > 160) {
            $name = substr($name, 0, 150) . '.' . $ext;
        }
        return $name;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return (string) max(1, (int) ceil($bytes / 1024)) . ' KB';
    }

    private static function writeMeta(array $meta): void
    {
        $path = self::folder() . '/' . $meta['id'] . '.json';
        file_put_contents($path, json_encode($meta));
        chmod($path, 0640);
    }

    private static function pageCount(string $path): ?int
    {
        $pdfinfo = binary_path('pdfinfo');
        if ($pdfinfo !== null) {
            $result = run_process([$pdfinfo, $path], null, 20);
            if (preg_match('/^Pages:\s+(\d+)/m', $result['stdout'], $m)) {
                return (int) $m[1];
            }
        }
        $qpdf = binary_path('qpdf');
        if ($qpdf !== null) {
            $result = run_process([$qpdf, '--show-npages', $path], null, 20);
            if (preg_match('/^\s*(\d+)\s*$/', $result['stdout'], $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    private static function webpEnabled(): bool
    {
        if (!function_exists('gd_info')) {
            return false;
        }
        $info = gd_info();
        return !empty($info['WebP Support']);
    }
}
