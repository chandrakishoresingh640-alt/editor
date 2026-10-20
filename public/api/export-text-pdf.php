<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
require dirname(__DIR__, 2) . '/includes/UnicodePdf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to export a PDF.', 405, 'method');
}
csrf_check();
$data = request_data();
$text = (string) ($data['text'] ?? '');
if (trim($text) === '') {
    throw new AppException('There is no text to export.', 422, 'empty');
}
if (strlen($text) > 400000) {
    $text = substr($text, 0, 400000);
}
$bytes = UnicodePdf::fromText($text);
$name = Storage::safeName((string) ($data['name'] ?? 'notes.pdf'), 'pdf');
$stored = Storage::put($bytes, $name, 'pdf');
json_response(['ok' => true, 'file' => ['id' => $stored['id'], 'name' => $stored['name'], 'mime' => $stored['mime'], 'size' => $stored['size']]]);
