<?php
declare(strict_types=1);

/**
 * Writes a Unicode PDF by embedding DejaVu Sans and a ToUnicode map.
 */
final class UnicodePdf
{
    private const FONT = APP_ROOT . '/public/assets/fonts/DejaVuSans.ttf';

    public static function fromText(string $text, string $title = 'Quire document'): string
    {
        if (!is_file(self::FONT)) {
            throw new AppException('The Unicode font file is missing on this server.', 500, 'font');
        }
        $font = TtfFont::load(self::FONT);
        $pages = self::paginate($font, $text);
        if ($pages === []) {
            $pages = [[]];
        }
        $fontBytes = (string) file_get_contents(self::FONT);
        $used = [];
        foreach ($pages as $lines) {
            foreach ($lines as $line) {
                foreach ($line['glyphs'] as $glyph) {
                    $used[$glyph['gid']] = $glyph['unicode'];
                }
            }
        }
        if ($used === []) {
            $used[0] = 0;
        }
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontStreamId = 6;
        $descriptorId = 5;
        $cidId = 4;
        $type0Id = 3;
        $toUnicodeId = 7;
        $objects[$fontStreamId] = '<< /Length ' . strlen($fontBytes) . ' /Length1 ' . strlen($fontBytes) . " >>\nstream\n" . $fontBytes . "\nendstream";
        $objects[$descriptorId] = '<< /Type /FontDescriptor /FontName /DejaVuSans /Flags 32 /FontBBox ['
            . $font->bbox() . '] /ItalicAngle 0 /Ascent ' . $font->ascent() . ' /Descent ' . $font->descent()
            . ' /CapHeight 700 /StemV 80 /FontFile2 ' . $fontStreamId . ' 0 R >>';
        $objects[$cidId] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /DejaVuSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor '
            . $descriptorId . ' 0 R /DW 600 /CIDToGIDMap /Identity /W [' . $font->widthArray($used) . '] >>';
        $objects[$toUnicodeId] = self::stream(self::toUnicode($used));
        $objects[$type0Id] = '<< /Type /Font /Subtype /Type0 /BaseFont /DejaVuSans /Encoding /Identity-H /DescendantFonts ['
            . $cidId . ' 0 R] /ToUnicode ' . $toUnicodeId . ' 0 R >>';
        $pageObjects = [];
        $next = 8;
        foreach ($pages as $lines) {
            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = self::stream(self::pageStream($lines));
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $type0Id . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $pageObjects[] = $pageId . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pageObjects) . ' /Kids [' . implode(' ', $pageObjects) . '] >>';
        unset($title);
        ksort($objects);
        return self::assemble($objects);
    }

    /** @param array<int,int> $used gid => unicode */
    private static function toUnicode(array $used): string
    {
        $chunks = array_chunk($used, 100, true);
        $body = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach ($chunks as $chunk) {
            $body .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $unicode) {
                $body .= sprintf("<%04X> <%04X>\n", $gid, $unicode);
            }
            $body .= "endbfchar\n";
        }
        $body .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        return $body;
    }

    /** @param array<int,array{glyphs:array<int,array{gid:int,unicode:int}>,size:float,y:float}> $lines */
    private static function pageStream(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $hex = '';
            foreach ($line['glyphs'] as $glyph) {
                $hex .= sprintf('%04X', $glyph['gid']);
            }
            if ($hex === '') {
                continue;
            }
            $out .= "BT\n/F1 " . self::num($line['size']) . " Tf\n1 0 0 1 54 " . self::num($line['y']) . " Tm\n<" . $hex . "> Tj\nET\n";
        }
        return $out;
    }

    /** @return array<int,array<int,array{glyphs:array,size:float,y:float}>> */
    private static function paginate(TtfFont $font, string $text): array
    {
        $size = 12.0;
        $maxWidth = 500.0;
        $paragraphs = preg_split("/\n/", str_replace("\r", '', $text)) ?: [];
        $wrapped = [];
        foreach ($paragraphs as $paragraph) {
            $words = $paragraph === '' ? [''] : (preg_split('/\s+/', $paragraph) ?: ['']);
            $current = [];
            $width = 0.0;
            foreach ($words as $word) {
                $glyphs = $font->glyphs($word === '' ? ' ' : $word);
                $wordWidth = $font->widthOf($glyphs, $size) + $font->widthOf($font->glyphs(' '), $size);
                if ($current !== [] && ($width + $wordWidth) > $maxWidth) {
                    $wrapped[] = $current;
                    $current = [];
                    $width = 0;
                }
                foreach ($glyphs as $glyph) {
                    $current[] = $glyph;
                }
                $current[] = $font->glyphs(' ')[0];
                $width += $wordWidth;
            }
            $wrapped[] = $current;
        }
        $pages = [];
        $page = [];
        $y = 740.0;
        foreach ($wrapped as $glyphs) {
            if ($y < 54) {
                $pages[] = $page;
                $page = [];
                $y = 740.0;
            }
            $page[] = ['glyphs' => $glyphs, 'size' => $size, 'y' => $y];
            $y -= 18;
        }
        if ($page !== []) {
            $pages[] = $page;
        }
        return $pages;
    }

    private static function stream(string $data): string
    {
        return '<< /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /** @param array<int,string> $objects */
    private static function assemble(array $objects): string
    {
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
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        require_once __DIR__ . '/OutputClean.php';
        return OutputClean::pdf($out);
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

final class TtfFont
{
    /** @param array<int,int> $cmap unicode => gid */
    private function __construct(
        private array $cmap,
        private array $advances,
        private int $units,
        private int $numGlyphs,
        private int $ascentRaw,
        private int $descentRaw,
        private string $bbox
    ) {
    }

    public static function load(string $path): self
    {
        $data = (string) file_get_contents($path);
        $tables = self::tables($data);
        foreach (['cmap', 'head', 'hhea', 'hmtx', 'maxp'] as $required) {
            if (!isset($tables[$required])) {
                throw new AppException('The font file is missing a required table.', 500, 'font');
            }
        }
        $head = substr($data, $tables['head'][0], $tables['head'][1]);
        $units = self::u16($head, 18);
        $bbox = self::s16($head, 36) . ' ' . self::s16($head, 38) . ' ' . self::s16($head, 40) . ' ' . self::s16($head, 42);
        $hhea = substr($data, $tables['hhea'][0], $tables['hhea'][1]);
        $ascent = self::s16($hhea, 4);
        $descent = self::s16($hhea, 6);
        $metrics = self::u16($hhea, 34);
        $maxp = substr($data, $tables['maxp'][0], $tables['maxp'][1]);
        $numGlyphs = self::u16($maxp, 4);
        $hmtx = substr($data, $tables['hmtx'][0], $tables['hmtx'][1]);
        $advances = [];
        $last = 500;
        for ($i = 0; $i < $numGlyphs; $i++) {
            if ($i < $metrics) {
                $last = self::u16($hmtx, $i * 4);
            }
            $advances[$i] = $last;
        }
        $cmap = self::cmap(substr($data, $tables['cmap'][0], $tables['cmap'][1]));
        return new self($cmap, $advances, $units ?: 1000, $numGlyphs, $ascent, $descent, $bbox);
    }

    /** @return array<int,array{gid:int,unicode:int}> */
    public function glyphs(string $text): array
    {
        $out = [];
        foreach (self::codepoints($text) as $code) {
            $gid = $this->cmap[$code] ?? 0;
            if ($gid < 0 || $gid >= $this->numGlyphs) {
                $gid = 0;
            }
            $out[] = ['gid' => $gid, 'unicode' => $code];
        }
        return $out;
    }

    /** @param array<int,array{gid:int,unicode:int}> $glyphs */
    public function widthOf(array $glyphs, float $size): float
    {
        $width = 0;
        foreach ($glyphs as $glyph) {
            $advance = $this->advances[$glyph['gid']] ?? 500;
            $width += $advance * $size / $this->units;
        }
        return $width;
    }

    /** @param array<int,int> $used */
    public function widthArray(array $used): string
    {
        $parts = [];
        foreach ($used as $gid => $unicode) {
            unset($unicode);
            $advance = $this->advances[(int) $gid] ?? 500;
            $width = (int) round($advance * 1000 / $this->units);
            $parts[] = (int) $gid . ' [' . $width . ']';
        }
        return implode(' ', $parts);
    }

    public function ascent(): int
    {
        return (int) round($this->ascentRaw * 1000 / $this->units);
    }

    public function descent(): int
    {
        return (int) round($this->descentRaw * 1000 / $this->units);
    }

    public function bbox(): string
    {
        return $this->bbox;
    }

    /** @return array<string,array{0:int,1:int}> */
    private static function tables(string $data): array
    {
        $count = self::u16($data, 4);
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = 12 + ($i * 16);
            $tag = substr($data, $offset, 4);
            $tables[$tag] = [self::u32($data, $offset + 8), self::u32($data, $offset + 12)];
        }
        return $tables;
    }

    /** @return array<int,int> */
    private static function cmap(string $table): array
    {
        $num = self::u16($table, 2);
        $chosen = null;
        $format = 0;
        for ($i = 0; $i < $num; $i++) {
            $platform = self::u16($table, 4 + ($i * 8));
            $encoding = self::u16($table, 6 + ($i * 8));
            $offset = self::u32($table, 8 + ($i * 8));
            $sub = self::u16($table, $offset);
            if (($platform === 3 && $encoding === 1 && $sub === 4) || ($platform === 0 && $sub === 4)) {
                $chosen = $offset;
                $format = 4;
                break;
            }
            if ($sub === 12 && $chosen === null) {
                $chosen = $offset;
                $format = 12;
            }
        }
        if ($chosen === null) {
            throw new AppException('The font has no usable character map.', 500, 'font');
        }
        return $format === 12 ? self::cmap12($table, $chosen) : self::cmap4($table, $chosen);
    }

    /** @return array<int,int> */
    private static function cmap4(string $table, int $offset): array
    {
        $segCount = intdiv(self::u16($table, $offset + 6), 2);
        $ends = $offset + 14;
        $starts = $ends + ($segCount * 2) + 2;
        $deltas = $starts + ($segCount * 2);
        $offsets = $deltas + ($segCount * 2);
        $map = [];
        for ($i = 0; $i < $segCount; $i++) {
            $end = self::u16($table, $ends + ($i * 2));
            $start = self::u16($table, $starts + ($i * 2));
            $delta = self::s16($table, $deltas + ($i * 2));
            $rangeOffset = self::u16($table, $offsets + ($i * 2));
            if ($end === 0xFFFF && $start === 0xFFFF) {
                continue;
            }
            for ($code = $start; $code <= $end; $code++) {
                if ($rangeOffset === 0) {
                    $gid = ($code + $delta) & 0xFFFF;
                } else {
                    $pos = $offsets + ($i * 2) + $rangeOffset + (($code - $start) * 2);
                    $gid = self::u16($table, $pos);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $map[$code] = $gid;
                }
            }
        }
        return $map;
    }

    /** @return array<int,int> */
    private static function cmap12(string $table, int $offset): array
    {
        $groups = self::u32($table, $offset + 12);
        $map = [];
        for ($i = 0; $i < $groups; $i++) {
            $pos = $offset + 16 + ($i * 12);
            $start = self::u32($table, $pos);
            $end = self::u32($table, $pos + 4);
            $glyph = self::u32($table, $pos + 8);
            if (($end - $start) > 20000) {
                continue;
            }
            for ($code = $start; $code <= $end; $code++) {
                $map[$code] = $glyph + ($code - $start);
            }
        }
        return $map;
    }

    /** @return array<int,int> */
    private static function codepoints(string $text): array
    {
        $out = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $c = ord($text[$i]);
            if ($c < 0x80) {
                $out[] = $c;
                $i++;
            } elseif ($c < 0xE0 && $i + 1 < $len) {
                $out[] = (($c & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($c < 0xF0 && $i + 2 < $len) {
                $out[] = (($c & 0x0F) << 12) | ((ord($text[$i + 1]) & 0x3F) << 6) | (ord($text[$i + 2]) & 0x3F);
                $i += 3;
            } elseif ($i + 3 < $len) {
                $out[] = (($c & 0x07) << 18) | ((ord($text[$i + 1]) & 0x3F) << 12) | ((ord($text[$i + 2]) & 0x3F) << 6) | (ord($text[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $i++;
            }
        }
        return $out;
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('n', substr($data, $offset, 2))[1] ?? 0;
    }

    private static function s16(string $data, int $offset): int
    {
        $value = self::u16($data, $offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private static function u32(string $data, int $offset): int
    {
        $parts = unpack('N', substr($data, $offset, 4));
        return (int) ($parts[1] ?? 0);
    }
}
