<?php
declare(strict_types=1);

/**
 * Removes document and image metadata from files this site creates.
 * Original uploads are not passed through here.
 */
final class OutputClean
{
    public static function pdf(string $bytes): string
    {
        require_once __DIR__ . '/PdfFile.php';
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new AppException('The new PDF could not be prepared.', 500, 'metadata');
        }
        try {
            return PdfFile::stripMetadata($bytes);
        } catch (AppException $e) {
            if ($e->errorCode() === 'encrypted') {
                throw $e;
            }
            $normalized = self::normalizePdf($bytes);
            if ($normalized === null) {
                throw $e;
            }
            return PdfFile::stripMetadata($normalized);
        }
    }

    public static function image(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return self::png($bytes);
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return self::webp($bytes);
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return self::gif($bytes);
        }
        return $bytes;
    }

    public static function jpeg(string $bytes): string
    {
        if (strlen($bytes) < 4 || !str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }
        $out = "\xFF\xD8";
        $i = 2;
        $len = strlen($bytes);
        while ($i < $len) {
            if ($bytes[$i] !== "\xFF") {
                return $bytes;
            }
            while ($i < $len && $bytes[$i] === "\xFF") {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $marker = ord($bytes[$i]);
            $i++;
            if ($marker === 0xD9) {
                $out .= "\xFF\xD9";
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF" . chr($marker);
                continue;
            }
            if ($i + 1 >= $len) {
                return $bytes;
            }
            $seglen = (ord($bytes[$i]) << 8) + ord($bytes[$i + 1]);
            if ($seglen < 2 || $i + $seglen > $len) {
                return $bytes;
            }
            $segment = substr($bytes, $i, $seglen);
            $i += $seglen;
            if ($marker === 0xDA) {
                $out .= "\xFF\xDA" . $segment;
                $start = $i;
                while ($i < $len) {
                    if ($bytes[$i] !== "\xFF") {
                        $i++;
                        continue;
                    }
                    $next = ord($bytes[$i + 1] ?? "\x00");
                    if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                        $i += 2;
                        continue;
                    }
                    if ($next === 0xFF) {
                        $i++;
                        continue;
                    }
                    break;
                }
                $out .= substr($bytes, $start, $i - $start);
                continue;
            }
            if (self::keepJpegMarker($marker)) {
                $out .= "\xFF" . chr($marker) . $segment;
            }
        }
        return str_starts_with($out, "\xFF\xD8") ? $out : $bytes;
    }

    public static function png(string $bytes): string
    {
        $sig = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($bytes, $sig)) {
            return $bytes;
        }
        $out = $sig;
        $i = 8;
        $len = strlen($bytes);
        while ($i + 12 <= $len) {
            $size = unpack('N', substr($bytes, $i, 4));
            $chunkSize = is_array($size) ? (int) $size[1] : 0;
            $type = substr($bytes, $i + 4, 4);
            $total = 12 + $chunkSize;
            if ($chunkSize < 0 || $i + $total > $len) {
                return $bytes;
            }
            $critical = $type[0] >= 'A' && $type[0] <= 'Z';
            if ($critical || $type === 'tRNS') {
                $out .= substr($bytes, $i, $total);
            }
            $i += $total;
            if ($type === 'IEND') {
                break;
            }
        }
        return $out;
    }

    public static function webp(string $bytes): string
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return $bytes;
        }
        $chunks = '';
        $i = 12;
        $len = strlen($bytes);
        while ($i + 8 <= $len) {
            $four = substr($bytes, $i, 4);
            $unpacked = unpack('V', substr($bytes, $i + 4, 4));
            $size = is_array($unpacked) ? (int) $unpacked[1] : 0;
            $start = $i + 8;
            if ($size < 0 || $start + $size > $len) {
                break;
            }
            $data = substr($bytes, $start, $size);
            $i = $start + $size + ($size % 2);
            if ($four === 'EXIF' || $four === 'XMP ' || $four === 'ICCP') {
                continue;
            }
            if ($four === 'VP8X' && $data !== '') {
                $data[0] = chr(ord($data[0]) & ~0x2C);
            }
            $chunks .= $four . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\x00" : '');
        }
        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    public static function gif(string $bytes): string
    {
        if (!str_starts_with($bytes, 'GIF87a') && !str_starts_with($bytes, 'GIF89a')) {
            return $bytes;
        }
        $len = strlen($bytes);
        if ($len < 13) {
            return $bytes;
        }
        $packed = ord($bytes[10]);
        $i = 13;
        if (($packed & 0x80) !== 0) {
            $i += 3 * (2 ** (($packed & 0x07) + 1));
        }
        if ($i > $len) {
            return $bytes;
        }
        $out = substr($bytes, 0, $i);
        while ($i < $len) {
            $block = ord($bytes[$i]);
            if ($block === 0x3B) {
                $out .= "\x3B";
                break;
            }
            if ($block === 0x2C) {
                $start = $i;
                $i += 10;
                if ($i > $len) {
                    return $bytes;
                }
                if ((ord($bytes[$i - 1]) & 0x80) !== 0) {
                    $i += 3 * (2 ** ((ord($bytes[$i - 1]) & 0x07) + 1));
                }
                if ($i >= $len) {
                    return $bytes;
                }
                $i++;
                $end = self::skipGifSubBlocks($bytes, $i);
                if ($end === null) {
                    return $bytes;
                }
                $out .= substr($bytes, $start, $end - $start);
                $i = $end;
                continue;
            }
            if ($block === 0x21) {
                $label = ord($bytes[$i + 1] ?? "\x00");
                $start = $i;
                $i += 2;
                if ($label === 0xFF && $i < $len && ord($bytes[$i]) === 11) {
                    $ident = substr($bytes, $i + 1, 8);
                    $drop = $ident === 'XMP Data' || $ident === 'ICCRGBG1';
                    $end = self::skipGifSubBlocks($bytes, $i);
                    if ($end === null) {
                        return $bytes;
                    }
                    if (!$drop) {
                        $out .= substr($bytes, $start, $end - $start);
                    }
                    $i = $end;
                    continue;
                }
                if ($label === 0xFE) {
                    $end = self::skipGifSubBlocks($bytes, $i);
                    if ($end === null) {
                        return $bytes;
                    }
                    $i = $end;
                    continue;
                }
                $end = self::skipGifSubBlocks($bytes, $i);
                if ($end === null) {
                    return $bytes;
                }
                $out .= substr($bytes, $start, $end - $start);
                $i = $end;
                continue;
            }
            return $bytes;
        }
        return $out;
    }

    private static function keepJpegMarker(int $marker): bool
    {
        if ($marker === 0xE0 || $marker === 0xEE) {
            return true;
        }
        if ($marker >= 0xE0 && $marker <= 0xEF) {
            return false;
        }
        return $marker !== 0xFE;
    }

    private static function skipGifSubBlocks(string $bytes, int $i): ?int
    {
        $len = strlen($bytes);
        while ($i < $len) {
            $size = ord($bytes[$i]);
            $i++;
            if ($size === 0) {
                return $i;
            }
            $i += $size;
        }
        return null;
    }

    private static function normalizePdf(string $bytes): ?string
    {
        if (!function_exists('binary_path') || !defined('APP_ROOT')) {
            return null;
        }
        $qpdf = binary_path('qpdf');
        if ($qpdf === null) {
            return null;
        }
        $dir = APP_ROOT . '/private_storage/processed';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $in = $dir . '/clean-in-' . bin2hex(random_bytes(6)) . '.bin';
        $out = $dir . '/clean-out-' . bin2hex(random_bytes(6)) . '.bin';
        file_put_contents($in, $bytes);
        chmod($in, 0640);
        try {
            $result = run_process([
                $qpdf,
                '--warning-exit-0',
                '--object-streams=disable',
                '--stream-data=uncompress',
                $in,
                $out,
            ], null, 90);
            if (!is_file($out) || filesize($out) < 8) {
                unset($result);
                return null;
            }
            $normalized = (string) file_get_contents($out);
            return str_starts_with($normalized, '%PDF-') ? $normalized : null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
