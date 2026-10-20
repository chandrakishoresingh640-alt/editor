<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
require dirname(__DIR__, 2) . '/includes/DocxBuilder.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to export a document.', 405, 'method');
}
csrf_check();
$data = request_data();
$blocks = $data['blocks'] ?? null;
if (!is_array($blocks)) {
    $text = (string) ($data['text'] ?? '');
    $blocks = array_map(static fn (string $line): array => ['text' => $line, 'size' => 12], preg_split("/\n/", $text) ?: []);
}
$normalized = [];
foreach ($blocks as $block) {
    if (!is_array($block)) {
        continue;
    }
    $normalized[] = [
        'text' => mb_substr((string) ($block['text'] ?? ''), 0, 5000),
        'size' => (float) ($block['size'] ?? 12),
    ];
    if (count($normalized) >= 2000) {
        break;
    }
}
$bytes = DocxBuilder::fromBlocks($normalized);
$name = Storage::safeName((string) ($data['name'] ?? 'document.docx'), 'docx');
if (!str_ends_with(strtolower($name), '.docx')) {
    $name .= '.docx';
}
$path = APP_ROOT . '/private_storage/uploads/' . Storage::owner();
if (!is_dir($path)) {
    mkdir($path, 0750, true);
}
$id = bin2hex(random_bytes(16));
file_put_contents($path . '/' . $id . '.bin', $bytes);
$meta = [
    'id' => $id,
    'owner' => Storage::owner(),
    'name' => $name,
    'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ext' => 'docx',
    'kind' => 'docx',
    'size' => strlen($bytes),
    'created' => time(),
];
file_put_contents($path . '/' . $id . '.json', json_encode($meta));
json_response(['ok' => true, 'file' => ['id' => $id, 'name' => $name, 'mime' => $meta['mime'], 'size' => $meta['size']]]);
