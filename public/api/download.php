<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/storage.php';
Storage::send((string) ($_GET['id'] ?? ''));
