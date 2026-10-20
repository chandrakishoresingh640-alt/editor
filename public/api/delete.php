<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to delete a file.', 405, 'method');
}
csrf_check();
$data = request_data();
Storage::delete((string) ($data['id'] ?? ''));
json_response(['ok' => true]);
