<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/http.php';
require dirname(__DIR__, 2) . '/includes/GoogleClient.php';

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
if ($code === '' || $state === '') {
    throw new AppException('Google did not return an authorization code.', 400, 'google_state');
}
$purpose = GoogleClient::handleCallback($code, $state);
$target = $purpose === 'youtube' ? '/tools/youtube-summarizer.php?google=connected' : '/tools/image-to-pdf-docs.php?google=connected';
header('Location: ' . url_path($target), true, 302);
exit;
