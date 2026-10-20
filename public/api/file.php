<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
$id = (string) ($_GET['id'] ?? '');
$meta = Storage::meta($id);
json_response(['ok' => true, 'file' => [
    'id' => $meta['id'],
    'name' => $meta['name'],
    'mime' => $meta['mime'],
    'kind' => $meta['kind'],
    'size' => $meta['size'],
    'pages' => $meta['pages'] ?? null,
]]);
