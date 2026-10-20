<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/jobs.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to process a PDF.', 405, 'method');
}
csrf_check();
rate_limit('process', (int) config('rate_process_per_hour'), 3600);
$data = request_data();
$stored = enhance_stored_pdf((string) ($data['id'] ?? ''), $data);
json_response([
    'ok' => true,
    'file' => [
        'id' => $stored['id'],
        'name' => $stored['name'],
        'mime' => $stored['mime'],
        'kind' => $stored['kind'],
        'size' => $stored['size'],
    ],
    'searchable' => $stored['searchable'] ?? false,
    'notice' => $stored['notice'] ?? null,
]);
