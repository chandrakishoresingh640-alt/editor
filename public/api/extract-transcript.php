<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/http.php';
require dirname(__DIR__, 2) . '/includes/YouTubeClient.php';
require dirname(__DIR__, 2) . '/includes/GoogleClient.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to look up a video.', 405, 'method');
}
csrf_check();
rate_limit('youtube', 30, 3600);
$data = request_data();
$videoId = YouTubeClient::videoId((string) ($data['url'] ?? ''));
$action = (string) ($data['action'] ?? 'metadata');

if ($action === 'captions') {
    if (!GoogleClient::configured()) {
        throw new AppException('Caption download needs Google OAuth, and it only works for videos owned by the connected channel. Paste a transcript instead.', 409, 'google_unconfigured');
    }
    try {
        $token = GoogleClient::token('youtube');
    } catch (AppException $e) {
        if ($e->errorCode() !== 'google_auth') {
            throw $e;
        }
        json_response(['ok' => true, 'auth_url' => GoogleClient::authorizationUrl('youtube')]);
    }
    $transcript = YouTubeClient::ownedCaptions($videoId, $token);
    json_response(['ok' => true, 'transcript' => $transcript]);
}

json_response(['ok' => true, 'video' => YouTubeClient::metadata($videoId)]);
