<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/http.php';
require dirname(__DIR__, 2) . '/includes/GoogleClient.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to create a document.', 405, 'method');
}
csrf_check();
if (!GoogleClient::configured()) {
    throw new AppException('Google Docs export is not configured. Download TXT, DOCX, or PDF instead.', 409, 'google_unconfigured');
}
$data = request_data();
$text = trim((string) ($data['text'] ?? ''));
if ($text === '') {
    throw new AppException('There is no text to send to Google Docs.', 422, 'empty');
}
try {
    GoogleClient::token('docs');
} catch (AppException $e) {
    if ($e->errorCode() !== 'google_auth') {
        throw $e;
    }
    json_response(['ok' => true, 'auth_url' => GoogleClient::authorizationUrl('docs')]);
}
$created = GoogleClient::createDocument((string) ($data['title'] ?? 'Quire document'), $text);
json_response(['ok' => true, 'url' => $created['url'], 'document_id' => $created['document_id']]);
