<?php
declare(strict_types=1);

/**
 * Builds a PDF from JPEG page images, with an optional invisible text layer.
 */
final class PdfBuilder
{
    /** @var array<int,array<string,mixed>> */
    private array $pages = [];

    public function addJpegPage(
        string $jpeg,
        float $pageWidth,
        float $pageHeight,
        float $imageX,
        float $imageY,
        float $imageWidth,
        float $imageHeight,
        array $words = []
    ): void {
        $info = @getimagesizefromstring($jpeg);
        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            throw new AppException('A page image could not be encoded as JPEG.', 500, 'image');
        }
        $this->pages[] = [
            'jpeg' => $jpeg,
            'px' => (int) $info[0],
            'py' => (int) $info[1],
            'page_w' => $pageWidth,
            'page_h' => $pageHeight,
            'x' => $imageX,
            'y' => $imageY,
            'w' => $imageWidth,
            'h' => $imageHeight,
            'words' => $words,
        ];
    }

    public function bytes(): string
    {
        if ($this->pages === []) {
            throw new AppException('There is nothing to put in the PDF.', 422, 'empty');
        }
        $objects = [];
        $pageIds = [];
        $fontId = 3;
        $objects[$fontId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $next = 4;
        $contentIds = [];
        $imageIds = [];
        foreach ($this->pages as $index => $page) {
            $imageId = $next++;
            $contentId = $next++;
            $pageId = $next++;
            $imageIds[$index] = $imageId;
            $contentIds[$index] = $contentId;
            $pageIds[] = $pageId;
            $objects[$imageId] = $this->imageObject($page);
            $objects[$contentId] = $this->streamObject($this->contentStream($page, $imageId));
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . $this->num($page['page_w']) . ' ' . $this->num($page['page_h'])
                . '] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> /XObject << /Im' . $imageId . ' ' . $imageId . ' 0 R >> >> /Contents '
                . $contentId . ' 0 R >>';
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . $kids . '] >>';
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $max = max(array_keys($objects));
        for ($i = 1; $i <= $max; $i++) {
            if (!isset($objects[$i])) {
                continue;
            }
            $offsets[$i] = strlen($out);
            $out .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xref = strlen($out);
        $size = $max + 1;
        $out .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    /** @param array<string,mixed> $page */
    private function imageObject(array $page): string
    {
        $data = (string) $page['jpeg'];
        return '<< /Type /XObject /Subtype /Image /Width ' . $page['px'] . ' /Height ' . $page['py']
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($data)
            . " >>\nstream\n" . $data . "\nendstream";
    }

    private function streamObject(string $data): string
    {
        return '<< /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /** @param array<string,mixed> $page */
    private function contentStream(array $page, int $imageId): string
    {
        $stream = "q\n" . $this->num($page['w']) . ' 0 0 ' . $this->num($page['h']) . ' '
            . $this->num($page['x']) . ' ' . $this->num($page['y']) . " cm\n/Im" . $imageId . " Do\nQ\n";
        $words = $page['words'];
        if ($words === []) {
            return $stream;
        }
        $scaleX = $page['px'] > 0 ? ($page['w'] / $page['px']) : 1;
        $scaleY = $page['py'] > 0 ? ($page['h'] / $page['py']) : 1;
        foreach ($words as $word) {
            $text = $this->winAnsi((string) ($word['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $left = (float) ($word['left'] ?? 0);
            $top = (float) ($word['top'] ?? 0);
            $height = (float) ($word['height'] ?? 12);
            $x = $page['x'] + $left * $scaleX;
            $y = $page['y'] + $page['h'] - (($top + $height) * $scaleY);
            $size = max(4, $height * $scaleY * 0.9);
            $stream .= "BT\n/F1 " . $this->num($size) . " Tf\n3 Tr\n1 0 0 1 " . $this->num($x) . ' ' . $this->num($y) . " Tm\n<"
                . bin2hex($text) . "> Tj\nET\n";
        }
        return $stream;
    }

    private function winAnsi(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        }
        return $converted;
    }

    private function num(float $value): string
    {
        $text = number_format($value, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }
}
