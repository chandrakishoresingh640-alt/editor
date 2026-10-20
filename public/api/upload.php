<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Upload with a POST request.', 405, 'method');
}
csrf_check();
rate_limit('upload', (int) config('rate_upload_per_hour'), 3600);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    throw new AppException('Choose a file to upload.', 422, 'empty');
}
$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = match ((int) $file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server upload limit.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
        default => 'The upload did not arrive.',
    };
    throw new AppException($message, 422, 'upload');
}
$bytes = file_get_contents((string) $file['tmp_name']);
if ($bytes === false) {
    throw new AppException('The upload could not be read.', 422, 'upload');
}
$kind = (string) ($_POST['kind'] ?? 'any');
if (!in_array($kind, ['any', 'pdf', 'image'], true)) {
    $kind = 'any';
}
$stored = Storage::put($bytes, (string) ($file['name'] ?? 'upload'), $kind);
json_response(['ok' => true, 'file' => public_file($stored)]);

function public_file(array $meta): array
{
    return [
        'id' => $meta['id'],
        'name' => $meta['name'],
        'mime' => $meta['mime'],
        'kind' => $meta['kind'],
        'size' => $meta['size'],
        'pages' => $meta['pages'] ?? null,
    ];
}
