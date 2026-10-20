<?php
declare(strict_types=1);

/**
 * Standard PDF security handler (ISO 32000).
 *
 * Authenticates one supplied password. It does not search, guess, or bypass
 * encryption. An empty password is accepted only when the document itself was
 * published with an empty user password (permission flags, not a secret).
 */
final class PdfCrypto
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    public static function normalize(string $password): string
    {
        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($password, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $password = $normalized;
            }
        }
        if (strlen($password) > 127) {
            $password = substr($password, 0, 127);
        }
        return $password;
    }

    /**
     * @param array<string,mixed> $dict Parsed encryption dictionary.
     * @return array{key:string,kind:string,string_method:string,stream_method:string,V:int,R:int,key_bytes:int}
     */
    public static function authenticate(array $dict, string $fileId, string $password): array
    {
        $v = (int) ($dict['V'] ?? 0);
        $r = (int) ($dict['R'] ?? 0);
        if (($dict['Filter'] ?? '') !== 'Standard') {
            throw new AppException('This PDF uses an encryption filter this app does not support.', 422, 'unsupported_encryption');
        }
        if (!in_array($v, [1, 2, 4, 5], true) || $r < 2 || $r > 6) {
            throw new AppException('This PDF uses an encryption revision this app does not support.', 422, 'unsupported_encryption');
        }
        $password = self::normalize($password);
        $methods = self::methods($dict);
        if ($v >= 5) {
            if (self::checkUserV5($dict, $password)) {
                $key = self::fileKeyV5($dict, $password, false);
                return self::context($key, 'user', $methods, $v, $r, 32);
            }
            if (self::checkOwnerV5($dict, $password)) {
                $key = self::fileKeyV5($dict, $password, true);
                return self::context($key, 'owner', $methods, $v, $r, 32);
            }
            throw new AppException('The password was not accepted.', 400, 'bad_password');
        }

        $keyBytes = self::keyLengthBytes($dict);
        if (self::checkUserV4($dict, $fileId, $password, $keyBytes)) {
            $key = self::fileKeyV4($dict, $fileId, $password, $keyBytes);
            return self::context($key, 'user', $methods, $v, $r, $keyBytes);
        }
        $recovered = self::recoverUserFromOwner($dict, $fileId, $password, $keyBytes);
        if ($recovered !== null && self::checkUserV4($dict, $fileId, $recovered, $keyBytes)) {
            $key = self::fileKeyV4($dict, $fileId, $recovered, $keyBytes);
            return self::context($key, 'owner', $methods, $v, $r, $keyBytes);
        }
        throw new AppException('The password was not accepted.', 400, 'bad_password');
    }

    public static function objectKey(string $fileKey, int $object, int $generation, bool $aes, int $v): string
    {
        if ($v >= 5) {
            return $fileKey;
        }
        $extra = chr($object & 255) . chr(($object >> 8) & 255) . chr(($object >> 16) & 255)
            . chr($generation & 255) . chr(($generation >> 8) & 255);
        if ($aes) {
            $extra .= 'sAlT';
        }
        $material = $fileKey . $extra;
        $digest = md5($material, true);
        $len = min(strlen($material), 16);
        return substr($digest, 0, $len);
    }

    public static function decryptPayload(string $data, string $objectKey, string $method): string
    {
        if ($method === 'none' || $data === '') {
            return $data;
        }
        if ($method === 'rc4') {
            return self::rc4($objectKey, $data);
        }
        // AES values carry a 16-byte IV plus at least one block. Shorter values
        // are plaintext, which is how cross-reference streams store /ID.
        if (strlen($data) < 32) {
            return $data;
        }
        if ((strlen($data) % 16) !== 0) {
            throw new AppException('An encrypted value in this PDF could not be read.', 422, 'damaged_pdf');
        }
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $algo = strlen($objectKey) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        $plain = openssl_decrypt($cipher, $algo, $objectKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new AppException('An encrypted value in this PDF could not be read.', 422, 'damaged_pdf');
        }
        return $plain;
    }

    /**
     * @param array<string,mixed> $dict
     * @return array{print:bool,modify:bool,copy:bool,annotate:bool,fill_forms:bool,accessibility:bool,assemble:bool,print_high:bool,raw:int}
     */
    public static function permissions(int $p): array
    {
        $bit = static fn (int $mask): bool => ($p & $mask) === $mask;
        return [
            'print' => $bit(4),
            'modify' => $bit(8),
            'copy' => $bit(16),
            'annotate' => $bit(32),
            'fill_forms' => $bit(256),
            'accessibility' => $bit(512),
            'assemble' => $bit(1024),
            'print_high' => $bit(2048),
            'raw' => $p,
        ];
    }

    /**
     * @param array{string_method:string,stream_method:string} $methods
     * @return array{key:string,kind:string,string_method:string,stream_method:string,V:int,R:int,key_bytes:int}
     */
    private static function context(string $key, string $kind, array $methods, int $v, int $r, int $keyBytes): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'string_method' => $methods['string_method'],
            'stream_method' => $methods['stream_method'],
            'V' => $v,
            'R' => $r,
            'key_bytes' => $keyBytes,
        ];
    }

    /**
     * @param array<string,mixed> $dict
     * @return array{string_method:string,stream_method:string}
     */
    private static function methods(array $dict): array
    {
        $v = (int) $dict['V'];
        if ($v < 4) {
            return ['string_method' => 'rc4', 'stream_method' => 'rc4'];
        }
        if ($v >= 5) {
            return ['string_method' => 'aes256', 'stream_method' => 'aes256'];
        }
        $map = static function (string $cfm): string {
            return match ($cfm) {
                'AESV2' => 'aes128',
                'AESV3' => 'aes256',
                'V2', '' => 'rc4',
                'Identity' => 'none',
                default => 'aes128',
            };
        };
        return [
            'string_method' => $map((string) ($dict['StrCFM'] ?? 'V2')),
            'stream_method' => $map((string) ($dict['StmCFM'] ?? 'V2')),
        ];
    }

    /** @param array<string,mixed> $dict */
    private static function keyLengthBytes(array $dict): int
    {
        $bits = (int) ($dict['Length'] ?? (((int) $dict['V']) <= 1 ? 40 : 128));
        $bytes = intdiv($bits, 8);
        if ($bytes < 5) {
            $bytes = 5;
        }
        if ($bytes > 16) {
            $bytes = 16;
        }
        return $bytes;
    }

    /** @param array<string,mixed> $dict */
    private static function fileKeyV4(array $dict, string $fileId, string $password, int $keyBytes): string
    {
        $md5 = hash_init('md5');
        hash_update($md5, self::pad32($password));
        hash_update($md5, self::fixed((string) $dict['O'], 32));
        $p = (int) $dict['P'];
        hash_update($md5, pack('V', $p & 0xFFFFFFFF));
        hash_update($md5, $fileId);
        if (((int) $dict['R']) >= 4 && empty($dict['EncryptMetadata'])) {
            hash_update($md5, "\xFF\xFF\xFF\xFF");
        }
        $digest = hash_final($md5, true);
        $rounds = ((int) $dict['R']) >= 3 ? 50 : 0;
        for ($i = 0; $i < $rounds; $i++) {
            $digest = md5(substr($digest, 0, $keyBytes), true);
        }
        return substr($digest, 0, $keyBytes);
    }

    /** @param array<string,mixed> $dict */
    private static function checkUserV4(array $dict, string $fileId, string $password, int $keyBytes): bool
    {
        $key = self::fileKeyV4($dict, $fileId, $password, $keyBytes);
        $u = (string) $dict['U'];
        if (((int) $dict['R']) >= 3) {
            $data = md5(self::PAD . $fileId, true);
            $data = self::iterateRc4($data, $key, $keyBytes, 20, false);
            return hash_equals(substr(self::fixed($u, 32), 0, 16), substr($data, 0, 16));
        }
        $data = self::iterateRc4(self::PAD, $key, $keyBytes, 1, false);
        return hash_equals(self::fixed($u, 32), self::fixed($data, 32));
    }

    /** @param array<string,mixed> $dict */
    private static function recoverUserFromOwner(array $dict, string $fileId, string $ownerPassword, int $keyBytes): ?string
    {
        unset($fileId);
        $md5 = md5(self::pad32($ownerPassword), true);
        $rounds = ((int) $dict['R']) >= 3 ? 50 : 0;
        for ($i = 0; $i < $rounds; $i++) {
            $md5 = md5(substr($md5, 0, $keyBytes), true);
        }
        $key = substr($md5, 0, $keyBytes);
        $data = self::iterateRc4(self::fixed((string) $dict['O'], 32), $key, $keyBytes, ((int) $dict['R']) >= 3 ? 20 : 1, true);
        return $data;
    }

    /** @param array<string,mixed> $dict */
    private static function checkUserV5(array $dict, string $password): bool
    {
        $u = (string) $dict['U'];
        if (strlen($u) < 40) {
            return false;
        }
        $hash = self::hashV5($password, substr($u, 32, 8), '', (int) $dict['R']);
        return hash_equals(substr($u, 0, 32), $hash);
    }

    /** @param array<string,mixed> $dict */
    private static function checkOwnerV5(array $dict, string $password): bool
    {
        $o = (string) $dict['O'];
        $u = (string) $dict['U'];
        if (strlen($o) < 40 || strlen($u) < 48) {
            return false;
        }
        $hash = self::hashV5($password, substr($o, 32, 8), substr($u, 0, 48), (int) $dict['R']);
        return hash_equals(substr($o, 0, 32), $hash);
    }

    /** @param array<string,mixed> $dict */
    private static function fileKeyV5(array $dict, string $password, bool $owner): string
    {
        if ($owner) {
            $salt = substr((string) $dict['O'], 40, 8);
            $userData = substr((string) $dict['U'], 0, 48);
            $wrapped = substr((string) $dict['OE'], 0, 32);
        } else {
            $salt = substr((string) $dict['U'], 40, 8);
            $userData = '';
            $wrapped = substr((string) $dict['UE'], 0, 32);
        }
        $intermediate = self::hashV5($password, $salt, $userData, (int) $dict['R']);
        $plain = openssl_decrypt($wrapped, 'aes-256-cbc', $intermediate, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        if (!is_string($plain) || strlen($plain) < 32) {
            throw new AppException('The encryption key could not be recovered from that password.', 422, 'damaged_pdf');
        }
        return substr($plain, 0, 32);
    }

    private static function hashV5(string $password, string $salt, string $userData, int $revision): string
    {
        $password = substr($password, 0, 127);
        $k = hash('sha256', $password . $salt . $userData, true);
        if ($revision < 6) {
            return $k;
        }
        $round = 0;
        while (true) {
            $round++;
            $block = $password . $k . $userData;
            $iv = substr($k, 16, 16);
            $key = substr($k, 0, 16);
            $encrypted = openssl_encrypt(str_repeat($block, 64), 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if (!is_string($encrypted) || $encrypted === '') {
                throw new AppException('The password could not be checked on this server.', 500, 'crypto');
            }
            $sum = 0;
            for ($i = 0; $i < 16; $i++) {
                $sum += ord($encrypted[$i]);
            }
            $mod = $sum % 3;
            $algo = $mod === 0 ? 'sha256' : ($mod === 1 ? 'sha384' : 'sha512');
            $k = hash($algo, $encrypted, true);
            if ($round >= 64 && ord($encrypted[strlen($encrypted) - 1]) <= ($round - 32)) {
                break;
            }
            if ($round > 200) {
                break;
            }
        }
        return substr($k, 0, 32);
    }

    private static function iterateRc4(string $data, string $key, int $keyLen, int $iterations, bool $reverse): string
    {
        $key = self::fixed($key, $keyLen);
        for ($i = 0; $i < $iterations; $i++) {
            $xor = $reverse ? ($iterations - 1 - $i) : $i;
            $roundKey = '';
            for ($j = 0; $j < $keyLen; $j++) {
                $roundKey .= chr(ord($key[$j]) ^ $xor);
            }
            $data = self::rc4($roundKey, $data);
        }
        return $data;
    }

    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $j = 0;
        $keyLen = strlen($key);
        if ($keyLen < 1) {
            return $data;
        }
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = 0;
        $j = 0;
        $out = '';
        $len = strlen($data);
        for ($n = 0; $n < $len; $n++) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }
        return $out;
    }

    private static function pad32(string $password): string
    {
        return substr(substr($password, 0, 32) . self::PAD, 0, 32);
    }

    private static function fixed(string $value, int $length): string
    {
        if (strlen($value) < $length) {
            return str_pad($value, $length, "\0");
        }
        return substr($value, 0, $length);
    }
}
