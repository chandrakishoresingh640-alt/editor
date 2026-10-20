<?php
declare(strict_types=1);

function proc_open_allowed(): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

function binary_path(string $name): ?string
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $allowed = ['gs', 'tesseract', 'pdftotext', 'pdfinfo', 'pdftoppm', 'qpdf', 'pdfunite'];
    if (!in_array($name, $allowed, true) || !proc_open_allowed()) {
        $cache[$name] = null;
        return null;
    }
    $path = null;
    $which = trim((string) shell_command_output(['sh', '-c', 'command -v ' . $name]));
    if ($which !== '' && is_executable($which)) {
        $path = $which;
    }
    $cache[$name] = $path;
    return $path;
}

function shell_command_output(array $command): string
{
    if (!proc_open_allowed()) {
        return '';
    }
    $result = run_process($command, null, 10);
    return $result['code'] === 0 ? $result['stdout'] : '';
}

function run_process(array $command, ?string $stdin = null, int $timeout = 120): array
{
    if (!proc_open_allowed()) {
        throw new AppException('This server cannot run external processing tools.', 500, 'proc_disabled');
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new AppException('A processing tool could not be started.', 500, 'proc_failed');
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $start = time();
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((time() - $start) > $timeout) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new AppException('Processing took too long and was stopped.', 504, 'timeout');
        }
        usleep(20000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code === -1 && isset($status['exitcode']) && $status['exitcode'] !== -1) {
        $code = $status['exitcode'];
    }
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function tesseract_languages(): array
{
    $bin = binary_path('tesseract');
    if ($bin === null) {
        return [];
    }
    $result = run_process([$bin, '--list-langs'], null, 15);
    $langs = [];
    foreach (preg_split('/\R/', $result['stdout'] . "\n" . $result['stderr']) as $line) {
        $line = trim($line);
        if (preg_match('/^[a-z]{3}(?:_[a-z0-9]+)?$/i', $line) && strtolower($line) !== 'osd') {
            $langs[] = strtolower($line);
        }
    }
    return array_values(array_unique($langs));
}

function browser_ocr_languages(): array
{
    $dir = dirname(__DIR__) . '/public/assets/vendor/tesseract/lang';
    $langs = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $file) {
            if (preg_match('/^([a-z]{3}(?:_[a-z0-9]+)?)\.traineddata(?:\.gz)?$/i', $file, $m)) {
                $langs[] = strtolower($m[1]);
            }
        }
    }
    return array_values(array_unique($langs));
}

function app_capabilities(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $gd = extension_loaded('gd');
    $webp = false;
    if ($gd && function_exists('gd_info')) {
        $info = gd_info();
        $webp = !empty($info['WebP Support']);
    }
    $cached = [
        'php' => PHP_VERSION,
        'gd' => $gd,
        'webp' => $webp,
        'imagick' => extension_loaded('imagick'),
        'zip' => extension_loaded('zip'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'fileinfo' => extension_loaded('fileinfo'),
        'intl' => extension_loaded('intl'),
        'ghostscript' => binary_path('gs') !== null,
        'tesseract' => binary_path('tesseract') !== null,
        'tesseract_languages' => tesseract_languages(),
        'poppler' => binary_path('pdfinfo') !== null,
        'qpdf' => binary_path('qpdf') !== null,
        'proc_open' => proc_open_allowed(),
        'ai_configured' => trim((string) config('ai_api_key')) !== '',
        'youtube_api_configured' => trim((string) config('youtube_api_key')) !== '',
        'google_oauth_configured' => trim((string) config('google_client_id')) !== ''
            && trim((string) config('google_client_secret')) !== ''
            && trim((string) config('google_redirect_uri')) !== '',
        'browser_ocr' => is_file(dirname(__DIR__) . '/public/assets/vendor/tesseract/tesseract.min.js'),
        'browser_ocr_languages' => browser_ocr_languages(),
        'max_upload_bytes' => effective_upload_limit(),
        'max_pdf_pages' => (int) config('max_pdf_pages'),
        'retention_minutes' => (int) config('retention_minutes'),
    ];
    return $cached;
}
