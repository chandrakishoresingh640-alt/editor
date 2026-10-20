<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$removed = cleanup_expired();
fwrite(STDOUT, 'Removed ' . $removed . " expired files.\n");
