<?php
declare(strict_types=1);

function assert_public_https(string $url): void
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host === '' || $host === 'localhost') {
        throw new AppException('The configured service address must be an https URL.', 500, 'config');
    }
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        throw new AppException('This server does not have the PHP curl extension, so external services are unavailable.', 500, 'curl');
    }
    assert_public_https($url);
    $ch = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Quire/1.0',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        app_log('HTTP ' . $method . ' failed: ' . $error);
        throw new AppException('The external service could not be reached.', 502, 'upstream');
    }
    return ['status' => $status, 'body' => (string) $raw, 'headers' => $responseHeaders];
}

function http_json(string $method, string $url, array $headers, ?array $payload, int $timeout = 20): array
{
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $response = http_request($method, $url, $headers, $body, $timeout);
    $decoded = json_decode($response['body'], true);
    return ['status' => $response['status'], 'json' => is_array($decoded) ? $decoded : null, 'body' => $response['body']];
}
