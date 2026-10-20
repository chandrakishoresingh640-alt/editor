<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/AppException.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/cleanup.php';

date_default_timezone_set((string) config('timezone', 'UTC'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $safe = $e instanceof AppException;
    $status = $safe ? $e->status() : 500;
    if (!$safe) {
        app_log('Unhandled ' . $e::class . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    $message = $safe ? $e->getMessage() : 'Something went wrong while handling that request.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    if (wants_json()) {
        $payload = ['ok' => false, 'error' => $message];
        if ($safe && $e->errorCode() !== 'error') {
            $payload['code'] = $e->errorCode();
            $payload = array_merge($payload, $e->extra());
        }
        json_response($payload, $status);
    }
    http_response_code($status);
    if (!function_exists('render_header')) {
        $layout = __DIR__ . '/layout.php';
        if (is_file($layout)) {
            require_once $layout;
        }
    }
    if (function_exists('render_header')) {
        render_header('Something went wrong');
        echo '<main class="wrap narrow"><article class="card pad"><p class="kicker">Request</p><h1>Something went wrong</h1><p>'
            . e($message) . '</p><p><a class="btn" href="' . e(url_path('/')) . '">Back to the workspace</a></p></article></main>';
        render_footer();
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title><p>' . e($message) . '</p>';
});

if (PHP_SAPI !== 'cli') {
    security_headers();
    secure_session();
    maybe_cleanup();
}
