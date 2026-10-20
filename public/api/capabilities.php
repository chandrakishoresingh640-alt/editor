<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
json_response(['ok' => true] + app_capabilities());
