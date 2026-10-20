<?php
declare(strict_types=1);

function maybe_cleanup(): void
{
    $stamp = dirname(__DIR__) . '/private_storage/logs/cleanup.stamp';
    $last = is_file($stamp) ? (int) @file_get_contents($stamp) : 0;
    if ((time() - $last) < 600) {
        return;
    }
    @file_put_contents($stamp, (string) time());
    cleanup_expired();
}

function cleanup_expired(): int
{
    $minutes = max(5, (int) config('retention_minutes'));
    $cutoff = time() - ($minutes * 60);
    $removed = 0;
    foreach (['uploads', 'processed'] as $folder) {
        $root = dirname(__DIR__) . '/private_storage/' . $folder;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile()) {
                $base = $file->getFilename();
                if ($base === '.gitkeep' || $base === '.htaccess' || str_starts_with($base, '.')) {
                    continue;
                }
                if ($file->getMTime() < $cutoff && @unlink($path)) {
                    $removed++;
                }
            } elseif ($file->isDir()) {
                @rmdir($path);
            }
        }
    }
    return $removed;
}
