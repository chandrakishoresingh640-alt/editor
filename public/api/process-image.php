<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/jobs.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to process an image.', 405, 'method');
}
csrf_check();
rate_limit('process', (int) config('rate_process_per_hour'), 3600);
$data = request_data();
$action = (string) ($data['action'] ?? '');

if ($action === 'ocr') {
    $meta = Storage::meta((string) ($data['id'] ?? ''));
    if ($meta['kind'] !== 'image') {
        throw new AppException('OCR expects an image.', 415, 'type');
    }
    $jpeg = ImageWorkshop::jpegBytes(Storage::bytes($meta['id']));
    $temp = temp_jpeg($jpeg);
    try {
        $ocr = ImageWorkshop::ocr($temp, (string) ($data['language'] ?? 'eng'));
    } finally {
        @unlink($temp);
    }
    json_response(['ok' => true, 'ocr' => $ocr, 'engine' => 'tesseract']);
}

if ($action === 'filters') {
    $jpeg = ImageWorkshop::apply(Storage::bytes((string) ($data['id'] ?? '')), normalize_image_options($data));
    $stored = Storage::put($jpeg, 'adjusted.jpg', 'image');
    json_response(['ok' => true, 'file' => ['id' => $stored['id'], 'name' => $stored['name'], 'mime' => $stored['mime']]]);
}

if ($action === 'pdf') {
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        throw new AppException('Choose the images to combine.', 422, 'empty');
    }
    $stored = images_to_stored_pdf(array_map('strval', $ids), $data);
    json_response([
        'ok' => true,
        'file' => ['id' => $stored['id'], 'name' => $stored['name'], 'mime' => $stored['mime'], 'size' => $stored['size']],
        'searchable' => $stored['searchable'] ?? false,
        'notice' => $stored['notice'] ?? null,
    ]);
}

throw new AppException('Unknown image action.', 400, 'action');
