<?php
declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'app_name' => 'Quire',
        'base_path' => '',
        'timezone' => 'UTC',
        'max_upload_bytes' => 26214400,
        'max_pdf_pages' => 30,
        'retention_minutes' => 60,
        'rate_upload_per_hour' => 40,
        'rate_process_per_hour' => 30,
        'rate_unlock_per_hour' => 12,
        'rate_ai_per_hour' => 20,
        'ai_api_key' => '',
        'ai_api_endpoint' => 'https://api.openai.com/v1',
        'ai_model' => 'gpt-4o-mini',
        'ai_timeout' => 45,
        'youtube_api_key' => '',
        'google_client_id' => '',
        'google_client_secret' => '',
        'google_redirect_uri' => '',
        'contact_email' => '',
        'trust_proxy' => false,
        'debug' => false,
    ];

    $root = dirname(__DIR__);
    $file = $root . '/config/environment.php';
    $loaded = [];
    if (is_file($file)) {
        $value = include $file;
        if (is_array($value)) {
            $loaded = $value;
        }
    }

    $envPath = $root . '/config/environment.env';
    if (is_file($envPath)) {
        $loaded = array_merge($loaded, parse_env_file($envPath));
    }

    $config = array_merge($defaults, $loaded);

    $map = [
        'QUIRE_APP_NAME' => 'app_name',
        'QUIRE_BASE_PATH' => 'base_path',
        'QUIRE_TIMEZONE' => 'timezone',
        'QUIRE_MAX_UPLOAD_BYTES' => 'max_upload_bytes',
        'QUIRE_MAX_PDF_PAGES' => 'max_pdf_pages',
        'QUIRE_RETENTION_MINUTES' => 'retention_minutes',
        'QUIRE_RATE_UPLOAD_PER_HOUR' => 'rate_upload_per_hour',
        'QUIRE_RATE_PROCESS_PER_HOUR' => 'rate_process_per_hour',
        'QUIRE_RATE_UNLOCK_PER_HOUR' => 'rate_unlock_per_hour',
        'QUIRE_RATE_AI_PER_HOUR' => 'rate_ai_per_hour',
        'AI_API_KEY' => 'ai_api_key',
        'AI_API_ENDPOINT' => 'ai_api_endpoint',
        'AI_MODEL' => 'ai_model',
        'AI_TIMEOUT' => 'ai_timeout',
        'YOUTUBE_API_KEY' => 'youtube_api_key',
        'GOOGLE_CLIENT_ID' => 'google_client_id',
        'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
        'GOOGLE_REDIRECT_URI' => 'google_redirect_uri',
        'CONTACT_EMAIL' => 'contact_email',
        'QUIRE_TRUST_PROXY' => 'trust_proxy',
        'QUIRE_DEBUG' => 'debug',
    ];
    $ints = [
        'max_upload_bytes', 'max_pdf_pages', 'retention_minutes',
        'rate_upload_per_hour', 'rate_process_per_hour', 'rate_unlock_per_hour',
        'rate_ai_per_hour', 'ai_timeout',
    ];
    $bools = ['trust_proxy', 'debug'];

    foreach ($map as $envName => $key) {
        $value = getenv($envName);
        if ($value === false || $value === '') {
            continue;
        }
        if (in_array($key, $ints, true)) {
            $config[$key] = (int) $value;
        } elseif (in_array($key, $bools, true)) {
            $config[$key] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        } else {
            $config[$key] = $value;
        }
    }

    foreach ($ints as $key) {
        $config[$key] = (int) $config[$key];
    }
    foreach ($bools as $key) {
        $config[$key] = (bool) $config[$key];
    }
    $config['ai_api_endpoint'] = rtrim((string) $config['ai_api_endpoint'], '/');
    $config['base_path'] = rtrim((string) $config['base_path'], '/');

    return $config;
}

function config(string $key, mixed $default = null): mixed
{
    $config = app_config();
    return $config[$key] ?? $default;
}

function parse_env_file(string $path): array
{
    $known = [
        'QUIRE_APP_NAME' => 'app_name',
        'QUIRE_BASE_PATH' => 'base_path',
        'QUIRE_TIMEZONE' => 'timezone',
        'QUIRE_MAX_UPLOAD_BYTES' => 'max_upload_bytes',
        'QUIRE_MAX_PDF_PAGES' => 'max_pdf_pages',
        'QUIRE_RETENTION_MINUTES' => 'retention_minutes',
        'AI_API_KEY' => 'ai_api_key',
        'AI_API_ENDPOINT' => 'ai_api_endpoint',
        'AI_MODEL' => 'ai_model',
        'AI_TIMEOUT' => 'ai_timeout',
        'YOUTUBE_API_KEY' => 'youtube_api_key',
        'GOOGLE_CLIENT_ID' => 'google_client_id',
        'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
        'GOOGLE_REDIRECT_URI' => 'google_redirect_uri',
        'CONTACT_EMAIL' => 'contact_email',
        'QUIRE_TRUST_PROXY' => 'trust_proxy',
        'QUIRE_DEBUG' => 'debug',
    ];
    $out = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = trim($value, "\"'");
        }
        if (isset($known[$name])) {
            $out[$known[$name]] = $value;
        }
    }
    return $out;
}
