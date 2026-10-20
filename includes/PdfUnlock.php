<?php
declare(strict_types=1);

require_once __DIR__ . '/PdfCrypto.php';
require_once __DIR__ . '/PdfFile.php';

/**
 * Unlocks a PDF the caller is allowed to modify.
 *
 * A user password or owner password must be supplied when the file does not
 * open without one. Permission-only files, which use an empty user password,
 * can have those flags removed after the caller confirms they are allowed to.
 * Passwords are never guessed.
 */
final class PdfUnlock
{
    public static function inspect(string $pdf): array
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new AppException('This file is not a PDF.', 422, 'invalid_pdf');
        }
        $qpdf = binary_path('qpdf');
        if ($qpdf !== null) {
            $info = self::inspectWithQpdf($qpdf, $pdf);
            if ($info !== null) {
                return $info;
            }
        }
        $info = PdfFile::inspect($pdf);
        $info['engine'] = 'php';
        return $info;
    }

    public static function unlock(string $pdf, string $password): array
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new AppException('This file is not a PDF.', 422, 'invalid_pdf');
        }
        $qpdf = binary_path('qpdf');
        if ($qpdf !== null) {
            return self::unlockWithQpdf($qpdf, $pdf, $password);
        }
        $result = PdfFile::decrypt($pdf, $password);
        $result['engine'] = 'php';
        return $result;
    }

    private static function inspectWithQpdf(string $qpdf, string $pdf): ?array
    {
        $path = self::temp('in', $pdf);
        try {
            $result = run_process([$qpdf, '--show-encryption', $path], null, 30);
            $text = $result['stderr'] . "\n" . $result['stdout'];
            if (str_contains($text, 'not encrypted')) {
                return self::plain();
            }
            if (self::looksDamaged($text) && !preg_match('/^R = (\d+)/m', $text)) {
                throw new AppException('This PDF looks damaged and could not be read.', 422, 'damaged_pdf');
            }
            $incorrect = str_contains(strtolower($text), 'incorrect password') || str_contains(strtolower($text), 'invalid password');
            $suppliedUser = str_contains($text, 'Supplied password is user password');
            if (!preg_match('/^R = (\d+)/m', $text, $rev)) {
                return null;
            }
            $permissions = self::permissionsFromText($text);
            return [
                'encrypted' => true,
                'readable' => true,
                'user_password_required' => $incorrect && !$suppliedUser,
                'unsupported' => null,
                'permissions' => $permissions,
                'revision' => (int) $rev[1],
                'version' => null,
                'filter' => 'Standard',
                'engine' => 'qpdf',
            ];
        } catch (AppException $e) {
            if (in_array($e->errorCode(), ['invalid_pdf', 'damaged_pdf'], true)) {
                throw $e;
            }
            return null;
        } finally {
            @unlink($path);
        }
    }

    private static function unlockWithQpdf(string $qpdf, string $pdf, string $password): array
    {
        $in = self::temp('in', $pdf);
        $out = self::temp('out', '');
        try {
            $shown = run_process([$qpdf, '--show-encryption', $in], null, 30);
            $shownText = $shown['stderr'] . "\n" . $shown['stdout'];
            if (self::looksDamaged($shownText) && !preg_match('/^R = (\d+)/m', $shownText)) {
                throw new AppException('This PDF looks damaged and could not be read.', 422, 'damaged_pdf');
            }
            if (str_contains($shownText, 'not encrypted')) {
                return [
                    'bytes' => $pdf,
                    'password_type' => 'none',
                    'unchanged' => true,
                    'engine' => 'qpdf',
                ];
            }
            $command = [$qpdf, '--warning-exit-0', '--decrypt'];
            if ($password !== '') {
                $command[] = '--password=' . $password;
            }
            $command[] = $in;
            $command[] = $out;
            $result = run_process($command, null, 90);
            $text = strtolower($result['stderr'] . "\n" . $result['stdout']);
            if (str_contains($text, 'incorrect password') || str_contains($text, 'invalid password')) {
                throw new AppException('The password was not accepted.', 400, 'bad_password');
            }
            if (!is_file($out) || filesize($out) < 8) {
                if (str_contains($text, "can't find pdf header") || str_contains($text, 'damaged')) {
                    throw new AppException('This PDF looks damaged and could not be read.', 422, 'damaged_pdf');
                }
                $fallback = PdfFile::decrypt($pdf, $password);
                $fallback['engine'] = 'php';
                return $fallback;
            }
            $bytes = (string) file_get_contents($out);
            if (!str_starts_with($bytes, '%PDF-')) {
                throw new AppException('The unlocked file could not be created.', 500, 'unlock');
            }
            $check = run_process([$qpdf, '--show-encryption', $out], null, 20);
            $still = $check['stdout'] . $check['stderr'];
            if (!str_contains($still, 'not encrypted')) {
                throw new AppException('The protection on this PDF could not be removed with the available tools.', 422, 'unsupported_encryption');
            }
            return [
                'bytes' => $bytes,
                'password_type' => $password === '' ? 'empty-user' : 'supplied',
                'unchanged' => false,
                'engine' => 'qpdf',
            ];
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private static function looksDamaged(string $text): bool
    {
        $lower = strtolower($text);
        foreach ([
            "can't find pdf header",
            'unable to find trailer',
            'unable to find /root',
            "can't find startxref",
            'file is damaged',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function plain(): array
    {
        return [
            'encrypted' => false,
            'readable' => true,
            'user_password_required' => false,
            'unsupported' => null,
            'permissions' => null,
            'revision' => null,
            'version' => null,
            'filter' => null,
            'engine' => 'qpdf',
        ];
    }

    private static function permissionsFromText(string $text): array
    {
        $flag = static function (string $label) use ($text): bool {
            return (bool) preg_match('/' . preg_quote($label, '/') . ':\s+allowed/i', $text);
        };
        return [
            'print' => $flag('print low resolution'),
            'print_high' => $flag('print high resolution'),
            'modify' => $flag('modify anything'),
            'copy' => $flag('extract for any purpose'),
            'annotate' => $flag('modify annotations'),
            'fill_forms' => $flag('modify forms'),
            'accessibility' => $flag('extract for accessibility'),
            'assemble' => $flag('modify document assembly'),
            'raw' => null,
        ];
    }

    private static function temp(string $prefix, string $bytes): string
    {
        $dir = APP_ROOT . '/private_storage/processed';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $path = $dir . '/' . $prefix . '-' . bin2hex(random_bytes(8)) . '.bin';
        file_put_contents($path, $bytes);
        chmod($path, 0640);
        return $path;
    }
}
