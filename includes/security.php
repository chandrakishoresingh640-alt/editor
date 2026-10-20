<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_log(string $message): void
{
    $dir = dirname(__DIR__) . '/private_storage/logs';
    if (!is_dir($dir)) {
        return;
    }
    $line = gmdate('c') . ' ' . str_replace(["\r", "\n"], ' ', $message) . "\n";
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: blob:; "
        . "style-src 'self' 'unsafe-inline'; " /* dynamic editor and preview positions */
        . "font-src 'self' data:; "
        . "script-src 'self'; "
        . "connect-src 'self'; "
        . "worker-src 'self' blob:; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

function secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('QUIRESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_base_path() === '' ? '/' : app_base_path() . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
    if (empty($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['owner'] = bin2hex(random_bytes(16));
    }
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        throw new AppException('The form expired. Reload the page and try again.', 403, 'csrf');
    }
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (config('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return $remote;
}

function rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    if ($max < 1) {
        return;
    }
    $dir = dirname(__DIR__) . '/private_storage/logs/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }
    $key = hash('sha256', $bucket . '|' . (session_id() ?: client_ip()));
    $path = $dir . '/' . $key . '.json';
    $now = time();
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return;
        }
        $raw = stream_get_contents($fh);
        $hits = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $hits = $decoded;
            }
        }
        $hits = array_values(array_filter($hits, static fn ($t) => is_int($t) && ($now - $t) < $windowSeconds));
        if (count($hits) >= $max) {
            throw new AppException('Too many requests. Wait a little while and try again.', 429, 'rate_limit');
        }
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function wants_json(): bool
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/api/')) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_response(array $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new AppException('The request was not valid JSON.');
    }
    return $data;
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        return array_merge($_POST, read_json_body());
    }
    return $_POST;
}

function app_base_path(): string
{
    $configured = (string) config('base_path', '');
    if ($configured !== '') {
        return $configured;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/tools/', '/api/', '/legal/', '/index.php', '/contact.php', '/about.php'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return rtrim(substr($script, 0, $pos), '/');
        }
    }
    return '';
}

function url_path(string $path): string
{
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return app_base_path() . $path;
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int) $value;
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => (int) $value,
    };
}

function effective_upload_limit(): int
{
    $limits = [(int) config('max_upload_bytes')];
    $upload = ini_bytes((string) ini_get('upload_max_filesize'));
    $post = ini_bytes((string) ini_get('post_max_size'));
    if ($upload > 0) {
        $limits[] = $upload;
    }
    if ($post > 0) {
        $limits[] = max(1, $post - 1024);
    }
    return min($limits);
}
