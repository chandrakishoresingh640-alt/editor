<?php
declare(strict_types=1);

/**
 * Reads a PDF enough to remove Standard encryption after PdfCrypto accepts
 * the supplied password, then writes an unencrypted PDF.
 */
final class PdfFile
{
    private string $data;
    private int $len;
    private int $p = 0;
    private int $depth = 0;
    private bool $decrypting = false;
    private int $currentObj = 0;
    private int $currentGen = 0;

    /** @var array<int,array<string,mixed>> */
    private array $xref = [];

    /** @var array<string,mixed> */
    private array $trailer = [];

    /** @var array<string,mixed>|null */
    private ?array $crypto = null;

    private int $encryptObj = 0;

    /** @var array<int,array{obj:int,gen:int,value:array}> */
    private array $objects = [];

    public function __construct(string $data)
    {
        if (!str_starts_with($data, '%PDF-')) {
            throw new AppException('This file is not a PDF.', 422, 'invalid_pdf');
        }
        $this->data = $data;
        $this->len = strlen($data);
    }

    public static function inspect(string $pdf): array
    {
        try {
            $file = new self($pdf);
            $file->readXref();
            return $file->describe();
        } catch (AppException $e) {
            if ($e->errorCode() === 'invalid_pdf') {
                throw $e;
            }
            return [
                'encrypted' => false,
                'readable' => false,
                'user_password_required' => false,
                'unsupported' => 'This PDF could not be inspected. It may be damaged or use a structure the built-in reader does not handle.',
                'permissions' => null,
                'revision' => null,
                'version' => null,
                'filter' => null,
            ];
        }
    }

    public static function decrypt(string $pdf, string $password): array
    {
        $file = new self($pdf);
        $file->readXref();
        $info = $file->describe();
        if (!$info['encrypted']) {
            return ['bytes' => $pdf, 'password_type' => 'none', 'unchanged' => true];
        }
        if (!empty($info['unsupported'])) {
            throw new AppException($info['unsupported'], 422, 'unsupported_encryption');
        }
        $prepared = $file->prepareCrypto($password);
        $file->loadObjects();
        return [
            'bytes' => $file->writeUnlocked(),
            'password_type' => $prepared['kind'],
            'unchanged' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function describe(): array
    {
        if (!isset($this->trailer['Encrypt'])) {
            return [
                'encrypted' => false,
                'readable' => true,
                'user_password_required' => false,
                'unsupported' => null,
                'permissions' => null,
                'revision' => null,
                'version' => null,
                'filter' => null,
            ];
        }
        try {
            $dict = $this->encryptionDictionary();
        } catch (AppException $e) {
            return [
                'encrypted' => true,
                'readable' => false,
                'user_password_required' => true,
                'unsupported' => $e->getMessage(),
                'permissions' => null,
                'revision' => null,
                'version' => null,
                'filter' => null,
            ];
        }
        $unsupported = null;
        if (($dict['Filter'] ?? '') !== 'Standard') {
            $unsupported = 'This PDF uses an encryption filter this app does not support.';
        } elseif (!in_array((int) $dict['V'], [1, 2, 4, 5], true) || (int) $dict['R'] < 2 || (int) $dict['R'] > 6) {
            $unsupported = 'This PDF uses an encryption revision this app does not support.';
        }
        $opens = false;
        if ($unsupported === null) {
            try {
                PdfCrypto::authenticate($dict, $this->fileId(), '');
                $opens = true;
            } catch (AppException $e) {
                if ($e->errorCode() !== 'bad_password') {
                    $unsupported = $e->getMessage();
                }
            }
        }
        return [
            'encrypted' => true,
            'readable' => $unsupported === null,
            'user_password_required' => !$opens,
            'unsupported' => $unsupported,
            'permissions' => PdfCrypto::permissions((int) ($dict['P'] ?? 0)),
            'revision' => (int) ($dict['R'] ?? 0),
            'version' => (int) ($dict['V'] ?? 0),
            'filter' => (string) ($dict['Filter'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function prepareCrypto(string $password): array
    {
        $dict = $this->encryptionDictionary();
        $crypto = PdfCrypto::authenticate($dict, $this->fileId(), $password);
        $crypto['EncryptMetadata'] = !empty($dict['EncryptMetadata']);
        $this->crypto = $crypto;
        $this->decrypting = true;
        return $crypto;
    }

    /** @return array<string,mixed> */
    private function encryptionDictionary(): array
    {
        $ref = $this->trailer['Encrypt'] ?? null;
        if (!is_array($ref) || ($ref['t'] ?? '') !== 'ref') {
            throw new AppException('The encryption dictionary could not be found.', 422, 'damaged_pdf');
        }
        $num = (int) $ref['obj'];
        $entry = $this->xref[$num] ?? null;
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'plain') {
            throw new AppException('This PDF stores its encryption dictionary in a compressed object. Install qpdf on the server to unlock this file.', 422, 'unsupported_encryption');
        }
        $this->encryptObj = $num;
        $parsed = $this->parseAt((int) $entry['offset'], false);
        if (($parsed['value']['t'] ?? '') !== 'dict') {
            throw new AppException('The encryption dictionary could not be read.', 422, 'damaged_pdf');
        }
        return $this->normalizeEncrypt($parsed['value']['v']);
    }

    /** @param array<string,array<string,mixed>> $map */
    private function normalizeEncrypt(array $map): array
    {
        $name = static function (array $map, string $key): string {
            $v = $map[$key] ?? null;
            return (is_array($v) && ($v['t'] ?? '') === 'name') ? (string) $v['v'] : '';
        };
        $str = static function (array $map, string $key): string {
            $v = $map[$key] ?? null;
            return (is_array($v) && ($v['t'] ?? '') === 'str') ? (string) $v['v'] : '';
        };
        $int = static function (array $map, string $key, int $default = 0): int {
            $v = $map[$key] ?? null;
            if (!is_array($v) || ($v['t'] ?? '') !== 'num') {
                return $default;
            }
            return (int) $v['raw'];
        };
        $cfm = '';
        $stmCfm = '';
        $cf = $map['CF']['v'] ?? null;
        $std = is_array($cf) ? ($cf['StdCF']['v'] ?? null) : null;
        if (is_array($std) && isset($std['CFM']) && ($std['CFM']['t'] ?? '') === 'name') {
            $cfm = (string) $std['CFM']['v'];
        }
        $strF = $name($map, 'StrF');
        $stmF = $name($map, 'StmF');
        if ($strF !== '' && $strF !== 'StdCF' && $strF !== 'Identity') {
            $cfm = $cfm !== '' ? $cfm : 'AESV2';
        }
        if ($stmF === 'Identity') {
            $stmCfm = 'Identity';
        } else {
            $stmCfm = $cfm;
        }
        if ($strF === 'Identity') {
            $cfm = 'Identity';
        }
        $encryptMeta = true;
        if (isset($map['EncryptMetadata']) && ($map['EncryptMetadata']['t'] ?? '') === 'bool') {
            $encryptMeta = (bool) $map['EncryptMetadata']['v'];
        }
        return [
            'Filter' => $name($map, 'Filter'),
            'V' => $int($map, 'V'),
            'R' => $int($map, 'R'),
            'P' => $int($map, 'P'),
            'Length' => $int($map, 'Length', 40),
            'O' => $str($map, 'O'),
            'U' => $str($map, 'U'),
            'OE' => $str($map, 'OE'),
            'UE' => $str($map, 'UE'),
            'Perms' => $str($map, 'Perms'),
            'StrCFM' => $cfm,
            'StmCFM' => $stmCfm,
            'EncryptMetadata' => $encryptMeta,
        ];
    }

    private function fileId(): string
    {
        $id = $this->trailer['ID'] ?? null;
        if (!is_array($id) || ($id['t'] ?? '') !== 'array') {
            return '';
        }
        $first = $id['v'][0] ?? null;
        if (is_array($first) && ($first['t'] ?? '') === 'str') {
            return (string) $first['v'];
        }
        return '';
    }

    private function readXref(): void
    {
        $start = $this->findStartxref();
        $seen = [];
        $pos = $start;
        $trailerSet = false;
        while ($pos !== null && !isset($seen[$pos])) {
            $seen[$pos] = true;
            $parsed = $this->parseXrefAt($pos);
            foreach ($parsed['entries'] as $num => $entry) {
                if (!isset($this->xref[$num])) {
                    $this->xref[$num] = $entry;
                }
            }
            if (!$trailerSet) {
                $this->trailer = $parsed['trailer'];
                $trailerSet = true;
            }
            $next = $parsed['prev'];
            if (!empty($parsed['xrefstm'])) {
                $extra = $this->parseXrefAt((int) $parsed['xrefstm']);
                foreach ($extra['entries'] as $num => $entry) {
                    if (!isset($this->xref[$num])) {
                        $this->xref[$num] = $entry;
                    }
                }
            }
            $pos = $next;
        }
        if ($this->xref === []) {
            throw new AppException('This PDF is missing its cross-reference table.', 422, 'damaged_pdf');
        }
    }

    private function findStartxref(): int
    {
        $idx = strrpos($this->data, 'startxref');
        if ($idx === false) {
            throw new AppException('This PDF is missing its cross-reference table.', 422, 'damaged_pdf');
        }
        $slice = substr($this->data, $idx + 9, 40);
        if (!preg_match('/\s*(\d+)/', $slice, $m)) {
            throw new AppException('This PDF is missing its cross-reference table.', 422, 'damaged_pdf');
        }
        return (int) $m[1];
    }

    /** @return array{entries:array<int,array<string,mixed>>,trailer:array<string,mixed>,prev:?int,xrefstm:?int} */
    private function parseXrefAt(int $offset): array
    {
        if ($offset < 0 || $offset >= $this->len) {
            throw new AppException('This PDF cross-reference table is damaged.', 422, 'damaged_pdf');
        }
        $this->p = $offset;
        $this->skipWs();
        if ($this->startsWith('xref')) {
            return $this->parseClassicXref();
        }
        $object = $this->parseIndirect(false);
        $value = $object['value'];
        if (($value['t'] ?? '') !== 'stream') {
            throw new AppException('This PDF cross-reference table is damaged.', 422, 'damaged_pdf');
        }
        $dict = $value['dict']['v'];
        $decoded = $this->decodeStream($value['dict'], $value['data']);
        $w = $this->numberList($dict['W'] ?? null);
        if (count($w) !== 3) {
            $w = [1, 2, 1];
        }
        $size = (int) (($dict['Size']['raw'] ?? 0));
        $index = $this->numberList($dict['Index'] ?? null);
        if ($index === []) {
            $index = [0, $size];
        }
        $entries = $this->readXrefRows($decoded, $w, $index);
        $trailer = $dict;
        $prev = isset($dict['Prev']) ? (int) $dict['Prev']['raw'] : null;
        return ['entries' => $entries, 'trailer' => $trailer, 'prev' => $prev, 'xrefstm' => null];
    }

    /** @return array{entries:array<int,array<string,mixed>>,trailer:array<string,mixed>,prev:?int,xrefstm:?int} */
    private function parseClassicXref(): array
    {
        $this->expectKeyword('xref');
        $entries = [];
        while (true) {
            $this->skipWs();
            if ($this->startsWith('trailer')) {
                break;
            }
            $start = $this->readInt();
            $count = $this->readInt();
            $this->consumeEol();
            for ($i = 0; $i < $count; $i++) {
                $row = substr($this->data, $this->p, 20);
                if (strlen($row) < 18) {
                    throw new AppException('This PDF cross-reference table is damaged.', 422, 'damaged_pdf');
                }
                $this->p += 20;
                $offset = (int) substr($row, 0, 10);
                $gen = (int) substr($row, 11, 5);
                $flag = $row[17] ?? 'f';
                $num = $start + $i;
                if ($flag === 'n') {
                    $entries[$num] = ['type' => 'plain', 'offset' => $offset, 'gen' => $gen];
                } else {
                    $entries[$num] = ['type' => 'free', 'offset' => $offset, 'gen' => $gen];
                }
            }
        }
        $this->expectKeyword('trailer');
        $trailerValue = $this->parseValue();
        if (($trailerValue['t'] ?? '') !== 'dict') {
            throw new AppException('This PDF trailer could not be read.', 422, 'damaged_pdf');
        }
        $trailer = $trailerValue['v'];
        $prev = isset($trailer['Prev']) ? (int) $trailer['Prev']['raw'] : null;
        $xrefstm = isset($trailer['XRefStm']) ? (int) $trailer['XRefStm']['raw'] : null;
        return ['entries' => $entries, 'trailer' => $trailer, 'prev' => $prev, 'xrefstm' => $xrefstm];
    }

    /**
     * @param array<int,int> $w
     * @param array<int,int> $index
     * @return array<int,array<string,mixed>>
     */
    private function readXrefRows(string $data, array $w, array $index): array
    {
        $rowLen = $w[0] + $w[1] + $w[2];
        if ($rowLen < 1) {
            throw new AppException('This PDF cross-reference table is damaged.', 422, 'damaged_pdf');
        }
        $entries = [];
        $cursor = 0;
        $pairs = count($index);
        for ($pair = 0; $pair + 1 < $pairs; $pair += 2) {
            $start = $index[$pair];
            $count = $index[$pair + 1];
            for ($i = 0; $i < $count; $i++) {
                if ($cursor + $rowLen > strlen($data)) {
                    break;
                }
                $row = substr($data, $cursor, $rowLen);
                $cursor += $rowLen;
                $pos = 0;
                $fields = [];
                foreach ($w as $width) {
                    $fields[] = $width === 0 ? 0 : self::beInt(substr($row, $pos, $width));
                    $pos += $width;
                }
                $type = $w[0] === 0 ? 1 : $fields[0];
                $num = $start + $i;
                if ($type === 0) {
                    $entries[$num] = ['type' => 'free', 'offset' => $fields[1], 'gen' => $fields[2]];
                } elseif ($type === 1) {
                    $entries[$num] = ['type' => 'plain', 'offset' => $fields[1], 'gen' => $fields[2]];
                } elseif ($type === 2) {
                    $entries[$num] = ['type' => 'compressed', 'stream' => $fields[1], 'index' => $fields[2], 'gen' => 0];
                }
            }
        }
        return $entries;
    }

    private function loadObjects(): void
    {
        foreach ($this->xref as $num => $entry) {
            if ($num === 0 || ($entry['type'] ?? '') !== 'plain') {
                continue;
            }
            if ($num === $this->encryptObj) {
                continue;
            }
            $parsed = $this->parseAt((int) $entry['offset'], true);
            $value = $parsed['value'];
            if ($this->dictName($value, 'Type') === 'XRef') {
                continue;
            }
            if ($this->dictName($value, 'Type') === 'ObjStm') {
                $this->expandObjectStream($parsed);
                continue;
            }
            $this->objects[$num] = $parsed;
        }
    }

    /** @param array{obj:int,gen:int,value:array} $parsed */
    private function expandObjectStream(array $parsed): void
    {
        $value = $parsed['value'];
        if (($value['t'] ?? '') !== 'stream') {
            return;
        }
        $decoded = $this->decodeStream($value['dict'], $value['data']);
        if (strlen($decoded) > 80 * 1024 * 1024) {
            throw new AppException('A compressed object in this PDF is too large to process.', 422, 'too_large');
        }
        $n = (int) ($value['dict']['v']['N']['raw'] ?? 0);
        $first = (int) ($value['dict']['v']['First']['raw'] ?? 0);
        if ($n < 1 || $first < 0 || $first > strlen($decoded)) {
            return;
        }
        preg_match_all('/\d+/', substr($decoded, 0, $first), $matches);
        $nums = $matches[0] ?? [];
        if (count($nums) < $n * 2) {
            throw new AppException('A compressed object in this PDF could not be read.', 422, 'damaged_pdf');
        }
        for ($i = 0; $i < $n; $i++) {
            $objNum = (int) $nums[$i * 2];
            $offset = $first + (int) $nums[$i * 2 + 1];
            $this->withBuffer($decoded, function () use ($objNum, $offset): void {
                $this->p = $offset;
                $this->currentObj = $objNum;
                $this->currentGen = 0;
                $this->decrypting = true;
                $inner = $this->parseValue();
                $this->objects[$objNum] = ['obj' => $objNum, 'gen' => 0, 'value' => $inner];
            });
        }
    }

    private function writeUnlocked(): string
    {
        if ($this->objects === []) {
            throw new AppException('Nothing could be written from this PDF.', 422, 'damaged_pdf');
        }
        $chunks = [];
        $offsets = [];
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $max = max(array_keys($this->objects));
        for ($num = 1; $num <= $max; $num++) {
            if (!isset($this->objects[$num])) {
                continue;
            }
            $object = $this->objects[$num];
            $offsets[$num] = strlen($out);
            $gen = (int) $object['gen'];
            $body = $this->writeValue($object['value']);
            $out .= $num . ' ' . $gen . " obj\n" . $body . "\nendobj\n";
        }
        $xrefAt = strlen($out);
        $size = $max + 1;
        $out .= "xref\n0 $size\n";
        $out .= "0000000000 65535 f \n";
        for ($num = 1; $num < $size; $num++) {
            if (isset($offsets[$num])) {
                $gen = (int) $this->objects[$num]['gen'];
                $out .= sprintf("%010d %05d n \n", $offsets[$num], $gen);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }
        $root = $this->trailer['Root'] ?? null;
        if (!is_array($root)) {
            throw new AppException('This PDF is missing its catalog.', 422, 'damaged_pdf');
        }
        $trailer = '<< /Size ' . $size . ' /Root ' . $this->writeValue($root);
        if (isset($this->trailer['Info'])) {
            $trailer .= ' /Info ' . $this->writeValue($this->trailer['Info']);
        }
        if (isset($this->trailer['ID'])) {
            $trailer .= ' /ID ' . $this->writeValue($this->trailer['ID']);
        }
        $trailer .= ' >>';
        $out .= "trailer\n" . $trailer . "\nstartxref\n" . $xrefAt . "\n%%EOF\n";
        unset($chunks);
        return $out;
    }

    /** @param array<string,mixed> $value */
    private function writeValue(array $value): string
    {
        return match ($value['t'] ?? '') {
            'null' => 'null',
            'bool' => !empty($value['v']) ? 'true' : 'false',
            'num' => (string) ($value['raw'] ?? '0'),
            'name' => '/' . $this->escapeName((string) ($value['v'] ?? '')),
            'str' => '<' . bin2hex((string) ($value['v'] ?? '')) . '>',
            'ref' => ((int) $value['obj']) . ' ' . ((int) $value['gen']) . ' R',
            'array' => $this->writeArray($value['v']),
            'dict' => $this->writeDict($value),
            'stream' => $this->writeStream($value),
            default => 'null',
        };
    }

    /** @param array<int,array<string,mixed>> $items */
    private function writeArray(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = $this->writeValue($item);
        }
        return '[ ' . implode(' ', $parts) . ' ]';
    }

    /** @param array{t:string,v:array<string,array<string,mixed>>} $dict */
    private function writeDict(array $dict): string
    {
        $parts = ['<<'];
        foreach ($dict['v'] as $key => $item) {
            $parts[] = '/' . $this->escapeName((string) $key) . ' ' . $this->writeValue($item);
        }
        $parts[] = '>>';
        return implode(' ', $parts);
    }

    /** @param array{t:string,dict:array,data:string} $stream */
    private function writeStream(array $stream): string
    {
        $dict = $stream['dict'];
        $dict['v']['Length'] = ['t' => 'num', 'raw' => (string) strlen($stream['data'])];
        return $this->writeDict($dict) . "\nstream\n" . $stream['data'] . "\nendstream";
    }

    private function escapeName(string $name): string
    {
        $out = '';
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $c = $name[$i];
            $o = ord($c);
            if ($o < 33 || $o > 126 || str_contains("()<>[]{}/%#", $c)) {
                $out .= sprintf('#%02X', $o);
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /** @return array{obj:int,gen:int,value:array} */
    private function parseAt(int $offset, bool $decrypt): array
    {
        $saved = [$this->p, $this->decrypting, $this->currentObj, $this->currentGen, $this->depth, $this->data, $this->len];
        $this->p = $offset;
        $this->decrypting = $decrypt;
        $this->depth = 0;
        try {
            return $this->parseIndirect($decrypt);
        } finally {
            [$this->p, $this->decrypting, $this->currentObj, $this->currentGen, $this->depth, $this->data, $this->len] = $saved;
        }
    }

    /** @return array{obj:int,gen:int,value:array} */
    private function parseIndirect(bool $decrypt): array
    {
        $this->decrypting = $decrypt;
        $obj = $this->readInt();
        $gen = $this->readInt();
        $this->expectKeyword('obj');
        $this->currentObj = $obj;
        $this->currentGen = $gen;
        $value = $this->parseValue();
        if (($value['t'] ?? '') === 'dict') {
            $mark = $this->p;
            $this->skipSpaces();
            if ($this->startsWith('stream')) {
                $value = $this->readStream($value);
            } else {
                $this->p = $mark;
            }
        }
        $this->skipWs();
        if ($this->startsWith('endobj')) {
            $this->expectKeyword('endobj');
        }
        return ['obj' => $obj, 'gen' => $gen, 'value' => $value];
    }

    /** @param array{t:string,v:array} $dict */
    private function readStream(array $dict): array
    {
        $this->expectKeyword('stream');
        $this->consumeEol();
        $length = $this->resolveLength($dict);
        if ($length < 0 || $this->p + $length > $this->len) {
            throw new AppException('A stream in this PDF is damaged.', 422, 'damaged_pdf');
        }
        $data = substr($this->data, $this->p, $length);
        $this->p += $length;
        $this->skipWs();
        if ($this->startsWith('endstream')) {
            $this->expectKeyword('endstream');
        }
        if ($this->shouldDecryptStream($dict)) {
            $method = (string) ($this->crypto['stream_method'] ?? 'rc4');
            $aes = str_starts_with($method, 'aes');
            $key = PdfCrypto::objectKey(
                (string) $this->crypto['key'],
                $this->currentObj,
                $this->currentGen,
                $aes,
                (int) $this->crypto['V']
            );
            $data = PdfCrypto::decryptPayload($data, $key, $method);
        }
        return ['t' => 'stream', 'dict' => $dict, 'data' => $data];
    }

    /** @param array{t:string,v:array} $dict */
    private function shouldDecryptStream(array $dict): bool
    {
        if (!$this->decrypting || $this->crypto === null) {
            return false;
        }
        if ($this->currentObj === $this->encryptObj) {
            return false;
        }
        $type = $this->dictName(['t' => 'dict', 'v' => $dict['v']], 'Type');
        if ($type === 'XRef') {
            return false;
        }
        if ($type === 'Metadata' && empty($this->crypto['EncryptMetadata'])) {
            return false;
        }
        return ($this->crypto['stream_method'] ?? 'rc4') !== 'none';
    }

    /** @param array{t:string,v:array} $dict */
    private function resolveLength(array $dict): int
    {
        $length = $dict['v']['Length'] ?? null;
        if (!is_array($length)) {
            throw new AppException('A stream in this PDF is missing its length.', 422, 'damaged_pdf');
        }
        if (($length['t'] ?? '') === 'num') {
            return (int) $length['raw'];
        }
        if (($length['t'] ?? '') === 'ref') {
            $num = (int) $length['obj'];
            $entry = $this->xref[$num] ?? null;
            if (!is_array($entry) || ($entry['type'] ?? '') !== 'plain') {
                throw new AppException('A stream length in this PDF could not be read.', 422, 'damaged_pdf');
            }
            $parsed = $this->parseAt((int) $entry['offset'], false);
            if (($parsed['value']['t'] ?? '') === 'num') {
                return (int) $parsed['value']['raw'];
            }
        }
        throw new AppException('A stream length in this PDF could not be read.', 422, 'damaged_pdf');
    }

    /** @param array{t:string,dict:array,data?:string} $dictValue */
    private function decodeStream(array $dictValue, string $data): string
    {
        $dict = $dictValue['t'] === 'dict' ? $dictValue : ($dictValue['dict'] ?? ['t' => 'dict', 'v' => []]);
        $filters = $this->filterNames($dict);
        $parms = $this->parmDicts($dict);
        foreach ($filters as $i => $filter) {
            $parm = $parms[$i] ?? [];
            if ($filter === 'FlateDecode' || $filter === 'Fl') {
                $data = $this->inflate($data);
                $predictor = (int) ($parm['Predictor'] ?? 1);
                if ($predictor >= 10) {
                    $columns = (int) ($parm['Columns'] ?? 1);
                    $data = $this->pngPredict($data, $columns);
                }
            } elseif ($filter === 'ASCIIHexDecode' || $filter === 'AHx') {
                $data = $this->asciiHex($data);
            } elseif ($filter === 'ASCII85Decode' || $filter === 'A85') {
                $data = $this->ascii85($data);
            } elseif ($filter === 'Crypt') {
                continue;
            } else {
                throw new AppException('This PDF uses a compression filter the built-in reader does not support.', 422, 'unsupported_filter');
            }
        }
        return $data;
    }

    /** @param array{t:string,v:array} $dict */
    private function filterNames(array $dict): array
    {
        $filter = $dict['v']['Filter'] ?? null;
        if (!is_array($filter)) {
            return [];
        }
        if (($filter['t'] ?? '') === 'name') {
            return [(string) $filter['v']];
        }
        if (($filter['t'] ?? '') === 'array') {
            $names = [];
            foreach ($filter['v'] as $item) {
                if (($item['t'] ?? '') === 'name') {
                    $names[] = (string) $item['v'];
                }
            }
            return $names;
        }
        return [];
    }

    /** @param array{t:string,v:array} $dict */
    private function parmDicts(array $dict): array
    {
        $parms = $dict['v']['DecodeParms'] ?? null;
        if (!is_array($parms) || ($parms['t'] ?? '') === 'null') {
            return [];
        }
        $one = function (array $node): array {
            if (($node['t'] ?? '') !== 'dict') {
                return [];
            }
            $out = [];
            foreach ($node['v'] as $key => $value) {
                if (($value['t'] ?? '') === 'num') {
                    $out[$key] = (int) $value['raw'];
                }
            }
            return $out;
        };
        if (($parms['t'] ?? '') === 'dict') {
            return [$one($parms)];
        }
        if (($parms['t'] ?? '') === 'array') {
            return array_map($one, $parms['v']);
        }
        return [];
    }

    private function inflate(string $data): string
    {
        $out = @gzuncompress($data);
        if ($out === false) {
            $out = @gzinflate($data);
        }
        if ($out === false) {
            throw new AppException('A compressed stream in this PDF could not be read.', 422, 'damaged_pdf');
        }
        return $out;
    }

    private function pngPredict(string $data, int $columns): string
    {
        if ($columns < 1) {
            $columns = 1;
        }
        $rowSize = $columns + 1;
        $rows = intdiv(strlen($data), $rowSize);
        $out = '';
        $prev = array_fill(0, $columns, 0);
        for ($r = 0; $r < $rows; $r++) {
            $row = substr($data, $r * $rowSize, $rowSize);
            $filter = ord($row[0]);
            $cur = [];
            for ($i = 0; $i < $columns; $i++) {
                $raw = ord($row[$i + 1]);
                $left = $i > 0 ? $cur[$i - 1] : 0;
                $up = $prev[$i];
                $upLeft = $i > 0 ? $prev[$i - 1] : 0;
                $val = match ($filter) {
                    0 => $raw,
                    1 => ($raw + $left) & 255,
                    2 => ($raw + $up) & 255,
                    3 => ($raw + intdiv($left + $up, 2)) & 255,
                    4 => ($raw + $this->paeth($left, $up, $upLeft)) & 255,
                    default => $raw,
                };
                $cur[$i] = $val;
                $out .= chr($val);
            }
            $prev = $cur;
        }
        return $out;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }
        return $c;
    }

    private function asciiHex(string $data): string
    {
        $hex = preg_replace('/\s+|%/', '', $data) ?? '';
        $hex = rtrim($hex, '>');
        if ((strlen($hex) % 2) === 1) {
            $hex .= '0';
        }
        $out = hex2bin($hex);
        return $out === false ? '' : $out;
    }

    private function ascii85(string $data): string
    {
        $data = preg_replace('/\s+/', '', $data) ?? '';
        if (str_ends_with($data, '~>')) {
            $data = substr($data, 0, -2);
        }
        $out = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            if ($data[$i] === 'z') {
                $out .= "\0\0\0\0";
                $i++;
                continue;
            }
            $chunk = substr($data, $i, 5);
            $i += strlen($chunk);
            $pads = 5 - strlen($chunk);
            $chunk = str_pad($chunk, 5, 'u');
            $value = 0;
            for ($c = 0; $c < 5; $c++) {
                $value = $value * 85 + (ord($chunk[$c]) - 33);
            }
            $bytes = chr(($value >> 24) & 255) . chr(($value >> 16) & 255) . chr(($value >> 8) & 255) . chr($value & 255);
            $out .= substr($bytes, 0, 4 - $pads);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function parseValue(): array
    {
        if (++$this->depth > 80) {
            throw new AppException('This PDF is too deeply nested to read.', 422, 'damaged_pdf');
        }
        try {
            $this->skipWs();
            if ($this->p >= $this->len) {
                throw new AppException('This PDF ended unexpectedly.', 422, 'damaged_pdf');
            }
            $c = $this->data[$this->p];
            if ($c === '/') {
                return $this->parseName();
            }
            if ($c === '(') {
                return $this->finishString($this->parseLiteral());
            }
            if ($c === '<') {
                if (($this->data[$this->p + 1] ?? '') === '<') {
                    return $this->parseDict();
                }
                return $this->finishString($this->parseHex());
            }
            if ($c === '[') {
                return $this->parseArray();
            }
            if ($c === '+' || $c === '-' || $c === '.' || ctype_digit($c)) {
                return $this->parseNumberOrRef();
            }
            if ($this->keyword('true')) {
                return ['t' => 'bool', 'v' => true];
            }
            if ($this->keyword('false')) {
                return ['t' => 'bool', 'v' => false];
            }
            if ($this->keyword('null')) {
                return ['t' => 'null'];
            }
            throw new AppException('This PDF contains data the built-in reader could not understand.', 422, 'damaged_pdf');
        } finally {
            $this->depth--;
        }
    }

    /** @return array<string,mixed> */
    private function parseDict(): array
    {
        $this->p += 2;
        $map = [];
        while (true) {
            $this->skipWs();
            if ($this->startsWith('>>')) {
                $this->p += 2;
                break;
            }
            $key = $this->parseName();
            $map[$key['v']] = $this->parseValue();
        }
        return ['t' => 'dict', 'v' => $map];
    }

    /** @return array<string,mixed> */
    private function parseArray(): array
    {
        $this->p++;
        $items = [];
        while (true) {
            $this->skipWs();
            if ($this->p >= $this->len) {
                throw new AppException('This PDF contains an unfinished list.', 422, 'damaged_pdf');
            }
            if ($this->data[$this->p] === ']') {
                $this->p++;
                break;
            }
            $items[] = $this->parseValue();
        }
        return ['t' => 'array', 'v' => $items];
    }

    /** @return array{t:string,v:string} */
    private function parseName(): array
    {
        if (($this->data[$this->p] ?? '') !== '/') {
            throw new AppException('This PDF contains a damaged name.', 422, 'damaged_pdf');
        }
        $this->p++;
        $name = '';
        while ($this->p < $this->len) {
            $c = $this->data[$this->p];
            if (str_contains(" \t\r\n\f()<>[]{}/%", $c)) {
                break;
            }
            if ($c === '#' && $this->p + 2 < $this->len) {
                $name .= chr(hexdec(substr($this->data, $this->p + 1, 2)));
                $this->p += 3;
                continue;
            }
            $name .= $c;
            $this->p++;
        }
        return ['t' => 'name', 'v' => $name];
    }

    private function parseLiteral(): string
    {
        $this->p++;
        $out = '';
        $depth = 1;
        while ($this->p < $this->len && $depth > 0) {
            $c = $this->data[$this->p];
            if ($c === '\\') {
                $n = $this->data[++$this->p] ?? '';
                if ($n === 'n') {
                    $out .= "\n";
                } elseif ($n === 'r') {
                    $out .= "\r";
                } elseif ($n === 't') {
                    $out .= "\t";
                } elseif ($n === 'b') {
                    $out .= "\x08";
                } elseif ($n === 'f') {
                    $out .= "\f";
                } elseif ($n === '(' || $n === ')' || $n === '\\') {
                    $out .= $n;
                } elseif ($n === "\r") {
                    if (($this->data[$this->p + 1] ?? '') === "\n") {
                        $this->p++;
                    }
                } elseif ($n === "\n") {
                    // continuation
                } elseif ($n >= '0' && $n <= '7') {
                    $oct = $n;
                    for ($k = 0; $k < 2; $k++) {
                        $d = $this->data[$this->p + 1] ?? '';
                        if ($d < '0' || $d > '7') {
                            break;
                        }
                        $oct .= $d;
                        $this->p++;
                    }
                    $out .= chr(octdec($oct) & 255);
                } else {
                    $out .= $n;
                }
                $this->p++;
                continue;
            }
            if ($c === '(') {
                $depth++;
                $out .= '(';
                $this->p++;
                continue;
            }
            if ($c === ')') {
                $depth--;
                if ($depth > 0) {
                    $out .= ')';
                }
                $this->p++;
                continue;
            }
            $out .= $c;
            $this->p++;
        }
        return $out;
    }

    private function parseHex(): string
    {
        $this->p++;
        $hex = '';
        while ($this->p < $this->len && $this->data[$this->p] !== '>') {
            $c = $this->data[$this->p];
            if (ctype_xdigit($c)) {
                $hex .= $c;
            }
            $this->p++;
        }
        if ($this->p < $this->len && $this->data[$this->p] === '>') {
            $this->p++;
        }
        if ((strlen($hex) % 2) === 1) {
            $hex .= '0';
        }
        $bin = hex2bin($hex);
        return $bin === false ? '' : $bin;
    }

    /** @return array<string,mixed> */
    private function finishString(string $value): array
    {
        if ($this->decrypting && $this->crypto !== null && $this->currentObj !== $this->encryptObj && $this->currentObj !== 0) {
            $method = (string) ($this->crypto['string_method'] ?? 'rc4');
            if ($method !== 'none' && $value !== '') {
                $aes = str_starts_with($method, 'aes');
                $key = PdfCrypto::objectKey(
                    (string) $this->crypto['key'],
                    $this->currentObj,
                    $this->currentGen,
                    $aes,
                    (int) $this->crypto['V']
                );
                $value = PdfCrypto::decryptPayload($value, $key, $method);
            }
        }
        return ['t' => 'str', 'v' => $value];
    }

    /** @return array<string,mixed> */
    private function parseNumberOrRef(): array
    {
        $mark = $this->p;
        $raw = $this->readNumberToken();
        $after = $this->p;
        $this->skipWs();
        if ($this->p < $this->len && (ctype_digit($this->data[$this->p]) || $this->data[$this->p] === '+' || $this->data[$this->p] === '-')) {
            $genMark = $this->p;
            $gen = $this->readNumberToken();
            $afterGen = $this->p;
            $this->skipWs();
            if ($this->keyword('R')) {
                return ['t' => 'ref', 'obj' => (int) $raw, 'gen' => (int) $gen];
            }
            $this->p = $genMark;
            unset($afterGen);
        }
        $this->p = $after;
        return ['t' => 'num', 'raw' => $raw];
    }

    private function readNumberToken(): string
    {
        $start = $this->p;
        if (($this->data[$this->p] ?? '') === '+' || ($this->data[$this->p] ?? '') === '-') {
            $this->p++;
        }
        $sawDigit = false;
        while ($this->p < $this->len && ctype_digit($this->data[$this->p])) {
            $sawDigit = true;
            $this->p++;
        }
        if ($this->p < $this->len && $this->data[$this->p] === '.') {
            $this->p++;
            while ($this->p < $this->len && ctype_digit($this->data[$this->p])) {
                $sawDigit = true;
                $this->p++;
            }
        }
        if (!$sawDigit) {
            throw new AppException('This PDF contains a damaged number.', 422, 'damaged_pdf');
        }
        return substr($this->data, $start, $this->p - $start);
    }

    private function readInt(): int
    {
        $this->skipWs();
        return (int) $this->readNumberToken();
    }

    private function keyword(string $word): bool
    {
        $len = strlen($word);
        if (substr($this->data, $this->p, $len) !== $word) {
            return false;
        }
        $next = $this->data[$this->p + $len] ?? '';
        if ($next !== '' && !str_contains(" \t\r\n\f()<>[]{}/%", $next)) {
            return false;
        }
        $this->p += $len;
        return true;
    }

    private function expectKeyword(string $word): void
    {
        $this->skipWs();
        if (!$this->keyword($word)) {
            throw new AppException('This PDF is missing an expected marker.', 422, 'damaged_pdf');
        }
    }

    private function startsWith(string $word): bool
    {
        return substr($this->data, $this->p, strlen($word)) === $word;
    }

    private function skipWs(): void
    {
        while ($this->p < $this->len) {
            $c = $this->data[$this->p];
            if ($c === '%') {
                while ($this->p < $this->len && $this->data[$this->p] !== "\n" && $this->data[$this->p] !== "\r") {
                    $this->p++;
                }
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                $this->p++;
                continue;
            }
            break;
        }
    }

    private function skipSpaces(): void
    {
        while ($this->p < $this->len) {
            $c = $this->data[$this->p];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                $this->p++;
                continue;
            }
            break;
        }
    }

    private function consumeEol(): void
    {
        if (($this->data[$this->p] ?? '') === "\r") {
            $this->p++;
            if (($this->data[$this->p] ?? '') === "\n") {
                $this->p++;
            }
            return;
        }
        if (($this->data[$this->p] ?? '') === "\n") {
            $this->p++;
        }
    }

    /** @param array<string,mixed> $value */
    private function dictName(array $value, string $key): string
    {
        $dict = ($value['t'] ?? '') === 'stream' ? ($value['dict']['v'] ?? []) : ($value['v'] ?? []);
        $item = $dict[$key] ?? null;
        if (is_array($item) && ($item['t'] ?? '') === 'name') {
            return (string) $item['v'];
        }
        return '';
    }

    /** @return array<int,int> */
    private function numberList(mixed $node): array
    {
        if (!is_array($node) || ($node['t'] ?? '') !== 'array') {
            return [];
        }
        $out = [];
        foreach ($node['v'] as $item) {
            if (($item['t'] ?? '') === 'num') {
                $out[] = (int) $item['raw'];
            }
        }
        return $out;
    }

    private function withBuffer(string $buffer, callable $fn): void
    {
        $saved = [$this->data, $this->len, $this->p, $this->depth];
        $this->data = $buffer;
        $this->len = strlen($buffer);
        $this->p = 0;
        $this->depth = 0;
        try {
            $fn();
        } finally {
            [$this->data, $this->len, $this->p, $this->depth] = $saved;
        }
    }

    private static function beInt(string $bytes): int
    {
        $value = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) + ord($bytes[$i]);
        }
        return $value;
    }
}
