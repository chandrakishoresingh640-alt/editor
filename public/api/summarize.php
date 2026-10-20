<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/http.php';
require dirname(__DIR__, 2) . '/includes/Summarizer.php';
require dirname(__DIR__, 2) . '/includes/AiClient.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use POST to summarize text.', 405, 'method');
}
csrf_check();
rate_limit('summarize', (int) config('rate_ai_per_hour'), 3600);
$data = request_data();
$text = trim((string) ($data['text'] ?? ''));
$length = (string) ($data['length'] ?? 'medium');
if (!in_array($length, ['short', 'medium', 'detailed'], true)) {
    $length = 'medium';
}
$useAi = !empty($data['use_ai']);
if ($useAi && AiClient::configured()) {
    try {
        $summary = AiClient::summarize($text, $length);
        json_response(['ok' => true, 'summary' => $summary]);
    } catch (AppException $e) {
        if (!in_array($e->errorCode(), ['ai_error', 'ai_unconfigured', 'upstream'], true)) {
            throw $e;
        }
        $summary = Summarizer::extractive($text, $length);
        $summary['notice'] = $e->getMessage();
        json_response(['ok' => true, 'summary' => $summary]);
    }
}
json_response(['ok' => true, 'summary' => Summarizer::extractive($text, $length)]);
