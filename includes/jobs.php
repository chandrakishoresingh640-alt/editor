<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/ImageWorkshop.php';
require_once __DIR__ . '/PdfBuilder.php';
require_once __DIR__ . '/DocxBuilder.php';
require_once __DIR__ . '/UnicodePdf.php';

function enhance_stored_pdf(string $id, array $input): array
{
    $meta = Storage::meta($id);
    if ($meta['kind'] !== 'pdf') {
        throw new AppException('Upload a PDF to enhance.', 415, 'type');
    }
    $options = normalize_image_options($input);
    $rendered = ImageWorkshop::renderPdfPages(Storage::path($id), (int) config('max_pdf_pages'));
    $builder = new PdfBuilder();
    $searchable = !empty($input['searchable']);
    $language = (string) ($input['language'] ?? 'eng');
    try {
        foreach ($rendered as $path) {
            $jpeg = ImageWorkshop::apply((string) file_get_contents($path), $options);
            $info = getimagesizefromstring($jpeg);
            $px = (int) ($info[0] ?? 1);
            $py = (int) ($info[1] ?? 1);
            [$x, $y, $w, $h, $pw, $ph] = ImageWorkshop::placement($px, $py, 'fit', 'portrait', 0);
            $words = [];
            if ($searchable && binary_path('tesseract') !== null) {
                $temp = temp_jpeg($jpeg);
                try {
                    $ocr = ImageWorkshop::ocr($temp, $language);
                    $words = $ocr['words'];
                } finally {
                    @unlink($temp);
                }
            }
            $builder->addJpegPage($jpeg, $pw, $ph, $x, $y, $w, $h, $words);
        }
    } finally {
        foreach ($rendered as $path) {
            @unlink($path);
        }
        if ($rendered !== []) {
            @rmdir(dirname($rendered[0]));
        }
    }
    $name = preg_replace('/\.pdf$/i', '', (string) $meta['name']) . '-enhanced.pdf';
    $stored = Storage::put($builder->bytes(), $name, 'pdf');
    $stored['searchable'] = $searchable && binary_path('tesseract') !== null;
    if ($searchable && binary_path('tesseract') === null) {
        $stored['notice'] = 'A searchable text layer needs Tesseract on the server, or the browser OCR option.';
    }
    return $stored;
}

function images_to_stored_pdf(array $ids, array $input): array
{
    if ($ids === []) {
        throw new AppException('Add at least one image.', 422, 'empty');
    }
    if (count($ids) > (int) config('max_pdf_pages')) {
        throw new AppException('Too many images for one PDF on this server.', 413, 'too_many_pages');
    }
    $pageSize = (string) ($input['page_size'] ?? 'a4');
    if (!in_array($pageSize, ['a4', 'letter', 'fit'], true)) {
        $pageSize = 'a4';
    }
    $orientation = ($input['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait';
    $margin = (float) ($input['margin'] ?? 36);
    $builder = new PdfBuilder();
    $searchable = !empty($input['searchable']);
    $language = (string) ($input['language'] ?? 'eng');
    foreach ($ids as $index => $id) {
        $meta = Storage::meta((string) $id);
        if ($meta['kind'] !== 'image') {
            throw new AppException('Every page must be an image.', 415, 'type');
        }
        $rotate = (int) (($input['rotations'][$index] ?? 0));
        $jpeg = ImageWorkshop::apply(Storage::bytes((string) $id), ['rotate' => $rotate]);
        $info = getimagesizefromstring($jpeg);
        $px = (int) ($info[0] ?? 1);
        $py = (int) ($info[1] ?? 1);
        [$x, $y, $w, $h, $pw, $ph] = ImageWorkshop::placement($px, $py, $pageSize, $orientation, $margin);
        $words = [];
        if ($searchable && binary_path('tesseract') !== null) {
            $temp = temp_jpeg($jpeg);
            try {
                $words = ImageWorkshop::ocr($temp, $language)['words'];
            } finally {
                @unlink($temp);
            }
        }
        $builder->addJpegPage($jpeg, $pw, $ph, $x, $y, $w, $h, $words);
    }
    $stored = Storage::put($builder->bytes(), 'images.pdf', 'pdf');
    $stored['searchable'] = $searchable && binary_path('tesseract') !== null;
    if ($searchable && binary_path('tesseract') === null) {
        $stored['notice'] = 'Server OCR is unavailable, so this PDF has no text layer. Use browser OCR on the image-to-PDF page to build a searchable file on your device.';
    }
    return $stored;
}

function normalize_image_options(array $input): array
{
    $level = (string) ($input['level'] ?? 'custom');
    $defaults = match ($level) {
        'light' => ['contrast' => 12, 'brightness' => 4, 'sharpen' => 25, 'denoise' => false],
        'strong' => ['contrast' => 36, 'brightness' => 8, 'sharpen' => 70, 'denoise' => true],
        'standard' => ['contrast' => 22, 'brightness' => 6, 'sharpen' => 45, 'denoise' => true],
        default => ['contrast' => 0, 'brightness' => 0, 'sharpen' => 0, 'denoise' => false],
    };
    return [
        'grayscale' => !empty($input['grayscale']),
        'brightness' => isset($input['brightness']) ? (int) $input['brightness'] : $defaults['brightness'],
        'contrast' => isset($input['contrast']) ? (int) $input['contrast'] : $defaults['contrast'],
        'sharpen' => isset($input['sharpen']) ? (int) $input['sharpen'] : $defaults['sharpen'],
        'denoise' => array_key_exists('denoise', $input) ? !empty($input['denoise']) : $defaults['denoise'],
        'deskew' => !empty($input['deskew']),
        'rotate' => (int) ($input['rotate'] ?? 0),
        'threshold' => !empty($input['threshold']),
        'threshold_level' => (int) ($input['threshold_level'] ?? 160),
    ];
}

function temp_jpeg(string $jpeg): string
{
    $path = APP_ROOT . '/private_storage/processed/ocr-' . bin2hex(random_bytes(6)) . '.jpg';
    file_put_contents($path, $jpeg);
    return $path;
}

function store_download(string $bytes, string $name, string $kind): array
{
    return Storage::put($bytes, $name, $kind);
}
