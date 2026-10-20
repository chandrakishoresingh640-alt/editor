<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
require dirname(__DIR__, 2) . '/includes/PdfUnlock.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $meta = Storage::meta((string) ($_GET['id'] ?? ''));
    if ($meta['kind'] !== 'pdf') {
        throw new AppException('Upload a PDF.', 415, 'type');
    }
    $info = PdfUnlock::inspect(Storage::bytes($meta['id']));
    unset($info['engine']);
    json_response(['ok' => true, 'info' => $info]);
}
if ($method !== 'POST') {
    throw new AppException('Use POST to unlock a PDF.', 405, 'method');
}
csrf_check();
rate_limit('unlock', (int) config('rate_unlock_per_hour'), 3600);
$data = request_data();
if (empty($data['authorized'])) {
    throw new AppException('Confirm that you are allowed to change this PDF.', 403, 'unauthorized');
}
$meta = Storage::meta((string) ($data['id'] ?? ''));
if ($meta['kind'] !== 'pdf') {
    throw new AppException('Upload a PDF.', 415, 'type');
}
$password = (string) ($data['password'] ?? '');
if (strlen($password) > 128) {
    throw new AppException('That password is too long.', 422, 'bad_password');
}
$result = PdfUnlock::unlock(Storage::bytes($meta['id']), $password);
$name = preg_replace('/\.pdf$/i', '', (string) $meta['name']) . '-unlocked.pdf';
$stored = Storage::put($result['bytes'], $name, 'pdf');
$note = !empty($result['unchanged'])
    ? 'The PDF was not encrypted. The original file is ready to download.'
    : 'A new PDF was created without the supported protection. The original upload was kept.';
json_response(['ok' => true, 'note' => $note, 'file' => ['id' => $stored['id'], 'name' => $stored['name'], 'mime' => $stored['mime'], 'size' => $stored['size']]]);
