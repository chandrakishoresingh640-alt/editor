<?php
declare(strict_types=1);

final class ImageWorkshop
{
    public static function jpegBytes(string $binary): string
    {
        if (!extension_loaded('gd')) {
            throw new AppException('This server does not have the GD image library.', 500, 'gd');
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new AppException('The image could not be read.', 422, 'image');
        }
        $image = self::flatten($image);
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        require_once __DIR__ . '/OutputClean.php';
        return OutputClean::jpeg($jpeg);
    }

    public static function apply(string $binary, array $options): string
    {
        if (!extension_loaded('gd')) {
            throw new AppException('This server does not have the GD image library.', 500, 'gd');
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new AppException('The image could not be read.', 422, 'image');
        }
        $image = self::flatten($image);
        $rotate = (int) ($options['rotate'] ?? 0);
        if (in_array($rotate, [90, 180, 270], true)) {
            $image = self::rotateRightAngles($image, $rotate);
        }
        if (!empty($options['grayscale'])) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }
        $brightness = (int) ($options['brightness'] ?? 0);
        if ($brightness !== 0) {
            imagefilter($image, IMG_FILTER_BRIGHTNESS, max(-255, min(255, $brightness)));
        }
        $contrast = (int) ($options['contrast'] ?? 0);
        if ($contrast !== 0) {
            imagefilter($image, IMG_FILTER_CONTRAST, max(-100, min(100, -$contrast)));
        }
        if (!empty($options['denoise'])) {
            imagefilter($image, IMG_FILTER_SMOOTH, 6);
        }
        $sharpen = (int) ($options['sharpen'] ?? 0);
        if ($sharpen > 0) {
            $amount = $sharpen >= 60 ? -1 : -0.5;
            $matrix = [
                [0, $amount, 0],
                [$amount, 5, $amount],
                [0, $amount, 0],
            ];
            imageconvolution($image, $matrix, 1, 0);
        }
        if (!empty($options['threshold'])) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            $level = (int) ($options['threshold_level'] ?? 160);
            self::threshold($image, $level);
        }
        if (!empty($options['deskew'])) {
            $angle = self::detectSkew($image);
            if (abs($angle) >= 0.3) {
                $rotated = imagerotate($image, $angle, imagecolorallocate($image, 255, 255, 255));
                if ($rotated !== false) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }
        ob_start();
        imagejpeg($image, null, 86);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        require_once __DIR__ . '/OutputClean.php';
        return OutputClean::jpeg($jpeg);
    }

    public static function renderPdfPages(string $pdfPath, int $maxPages): array
    {
        $gs = binary_path('gs');
        if ($gs === null) {
            throw new AppException('Ghostscript is not installed on this server. Use the browser enhancement on this page, which does not need it.', 409, 'ghostscript');
        }
        $count = self::pdfPages($pdfPath);
        if ($count !== null && $count > $maxPages) {
            throw new AppException('This PDF has too many pages for server processing. The limit is ' . $maxPages . '.', 413, 'too_many_pages');
        }
        $pages = $count ?? $maxPages;
        $dir = APP_ROOT . '/private_storage/processed/' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new AppException('A temporary folder could not be created.', 500, 'storage');
        }
        $files = [];
        $limit = min($pages, $maxPages);
        for ($page = 1; $page <= $limit; $page++) {
            $target = $dir . '/page-' . $page . '.jpg';
            $result = run_process([
                $gs, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET',
                '-sDEVICE=jpeg', '-dJPEGQ=82', '-r144', '-dMaxBitmap=32000000',
                '-dFirstPage=' . $page, '-dLastPage=' . $page,
                '-sOutputFile=' . $target, $pdfPath,
            ], null, 90);
            if (!is_file($target) || filesize($target) < 100) {
                if ($page === 1) {
                    throw new AppException('Ghostscript could not render this PDF. It may be encrypted or damaged.', 422, 'render');
                }
                break;
            }
            unset($result);
            $files[] = $target;
        }
        return $files;
    }

    public static function pdfPages(string $path): ?int
    {
        $pdfinfo = binary_path('pdfinfo');
        if ($pdfinfo !== null) {
            $result = run_process([$pdfinfo, $path], null, 20);
            if (preg_match('/^Pages:\s+(\d+)/m', $result['stdout'], $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    public static function ocr(string $imagePath, string $language): array
    {
        $bin = binary_path('tesseract');
        if ($bin === null) {
            throw new AppException('Tesseract OCR is not installed on this server. Use the browser OCR button, which runs on your device.', 409, 'tesseract');
        }
        $language = strtolower($language);
        $available = tesseract_languages();
        if (!preg_match('/^[a-z]{3}(?:_[a-z0-9]+)?$/', $language) || ($available !== [] && !in_array($language, $available, true))) {
            throw new AppException('That OCR language is not installed on this server.', 422, 'language');
        }
        $result = run_process([$bin, $imagePath, 'stdout', '-l', $language, '--psm', '6', 'tsv'], null, 90);
        if ($result['code'] !== 0 && trim($result['stdout']) === '') {
            throw new AppException('Tesseract could not read this image.', 422, 'ocr');
        }
        return self::parseTsv($result['stdout']);
    }

    public static function parseTsv(string $tsv): array
    {
        $lines = preg_split('/\R/', trim($tsv)) ?: [];
        $words = [];
        $textLines = [];
        foreach ($lines as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }
            $level = (int) $cols[0];
            $conf = (float) $cols[10];
            $word = trim($cols[11]);
            if ($level !== 5 || $word === '' || $conf < 0) {
                continue;
            }
            $item = [
                'text' => $word,
                'left' => (int) $cols[6],
                'top' => (int) $cols[7],
                'width' => (int) $cols[8],
                'height' => (int) $cols[9],
                'confidence' => $conf,
                'line' => (int) $cols[4],
                'block' => (int) $cols[2],
            ];
            $words[] = $item;
            $key = $item['block'] . ':' . $item['line'];
            $textLines[$key][] = $item;
        }
        $rows = [];
        $plain = [];
        foreach ($textLines as $group) {
            $text = implode(' ', array_map(static fn (array $w): string => $w['text'], $group));
            $first = $group[0];
            $last = $group[count($group) - 1];
            $rows[] = [
                'text' => $text,
                'left' => $first['left'],
                'top' => $first['top'],
                'width' => ($last['left'] + $last['width']) - $first['left'],
                'height' => max(array_map(static fn (array $w): int => $w['height'], $group)),
            ];
            $plain[] = $text;
        }
        return ['text' => trim(implode("\n", $plain)), 'words' => $words, 'lines' => $rows];
    }

    /** @return array{0:float,1:float,2:float,3:float} x,y,w,h in points and page size */
    public static function placement(int $pixelW, int $pixelH, string $pageSize, string $orientation, float $margin): array
    {
        if ($pageSize === 'fit') {
            $w = max(72, $pixelW * 72 / 144);
            $h = max(72, $pixelH * 72 / 144);
            return [0.0, 0.0, $w, $h, $w, $h];
        }
        $sizes = ['a4' => [595.28, 841.89], 'letter' => [612.0, 792.0]];
        [$pw, $ph] = $sizes[$pageSize] ?? $sizes['a4'];
        if ($orientation === 'landscape') {
            [$pw, $ph] = [$ph, $pw];
        }
        $margin = max(0, min(72, $margin));
        $maxW = $pw - (2 * $margin);
        $maxH = $ph - (2 * $margin);
        $scale = min($maxW / max(1, $pixelW), $maxH / max(1, $pixelH));
        $drawW = $pixelW * $scale;
        $drawH = $pixelH * $scale;
        $x = ($pw - $drawW) / 2;
        $y = ($ph - $drawH) / 2;
        return [$x, $y, $drawW, $drawH, $pw, $ph];
    }

    private static function flatten($image)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w, $h, $white);
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
        imagedestroy($image);
        return $flat;
    }

    private static function rotateRightAngles($image, int $degrees)
    {
        $turns = match ($degrees) {
            90 => 270,
            270 => 90,
            default => 180,
        };
        $rotated = imagerotate($image, $turns, 0);
        imagedestroy($image);
        if ($rotated === false) {
            throw new AppException('The image could not be rotated.', 500, 'image');
        }
        return $rotated;
    }

    private static function threshold($image, int $level): void
    {
        $w = imagesx($image);
        $h = imagesy($image);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $gray = ($rgb >> 16) & 255;
                $value = $gray >= $level ? 255 : 0;
                $color = imagecolorallocate($image, $value, $value, $value);
                imagesetpixel($image, $x, $y, $color);
            }
        }
    }

    private static function detectSkew($image): float
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $sampleW = 360;
        $scale = $w > $sampleW ? $sampleW / $w : 1;
        $sw = max(20, (int) ($w * $scale));
        $sh = max(20, (int) ($h * $scale));
        $sample = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagefilter($sample, IMG_FILTER_GRAYSCALE);
        $best = 0.0;
        $bestScore = -1.0;
        for ($angle = -6.0; $angle <= 6.0; $angle += 0.5) {
            $rotated = $angle === 0.0 ? $sample : imagerotate($sample, $angle, 0xFFFFFF);
            if ($rotated === false) {
                continue;
            }
            $score = self::projectionScore($rotated);
            if ($rotated !== $sample) {
                imagedestroy($rotated);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $angle;
            }
        }
        imagedestroy($sample);
        return $best;
    }

    private static function projectionScore($image): float
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $rows = [];
        for ($y = 0; $y < $h; $y += 2) {
            $dark = 0;
            for ($x = 0; $x < $w; $x += 2) {
                $gray = (imagecolorat($image, $x, $y) >> 16) & 255;
                if ($gray < 140) {
                    $dark++;
                }
            }
            $rows[] = $dark;
        }
        if ($rows === []) {
            return 0;
        }
        $mean = array_sum($rows) / count($rows);
        $var = 0;
        foreach ($rows as $value) {
            $var += ($value - $mean) ** 2;
        }
        return $var / count($rows);
    }
}
