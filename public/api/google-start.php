<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/http.php';
require dirname(__DIR__, 2) . '/includes/GoogleClient.php';
csrf_check();
$data = request_data();
$purpose = (string) ($data['purpose'] ?? 'docs');
json_response(['ok' => true, 'auth_url' => GoogleClient::authorizationUrl($purpose)]);
