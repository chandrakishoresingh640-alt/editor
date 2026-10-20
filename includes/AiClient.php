<?php
declare(strict_types=1);

/**
 * Replaceable chat-completions client. Any provider that accepts the
 * OpenAI-compatible /chat/completions request can be configured with
 * AI_API_ENDPOINT, AI_API_KEY, and AI_MODEL.
 */
final class AiClient
{
    public static function configured(): bool
    {
        return trim((string) config('ai_api_key')) !== '';
    }

    public static function summarize(string $transcript, string $length): array
    {
        if (!self::configured()) {
            throw new AppException('No AI provider is configured. The local summary was used instead.', 409, 'ai_unconfigured');
        }
        $endpoint = rtrim((string) config('ai_api_endpoint'), '/');
        assert_public_https($endpoint . '/chat/completions');
        $clipped = $transcript;
        $truncated = false;
        if (strlen($clipped) > 24000) {
            $clipped = substr($clipped, 0, 24000);
            $truncated = true;
        }
        $lengthLabel = match ($length) {
            'short' => 'short (about 120 words)',
            'detailed' => 'detailed (about 500 words)',
            default => 'medium (about 250 words)',
        };
        $payload = [
            'model' => (string) config('ai_model'),
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You summarize transcripts the user already has. Use only facts present in the transcript. Do not invent quotes, names, or steps. Return JSON with keys overview (string), key_points (array of strings), sections (array of {title, summary}), takeaways (array of strings), action_items (array of strings). If there are no action items, return an empty array.',
                ],
                [
                    'role' => 'user',
                    'content' => "Summary length: {$lengthLabel}.\n\nTranscript:\n" . $clipped,
                ],
            ],
        ];
        $response = http_json(
            'POST',
            $endpoint . '/chat/completions',
            ['Authorization: Bearer ' . trim((string) config('ai_api_key'))],
            $payload,
            (int) config('ai_timeout')
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            app_log('AI provider returned HTTP ' . $response['status']);
            throw new AppException('The AI provider returned an error. The local summary is still available.', 502, 'ai_error');
        }
        $content = (string) ($response['json']['choices'][0]['message']['content'] ?? '');
        $parsed = self::decodeContent($content);
        $parsed['method'] = 'ai';
        $parsed['truncated'] = $truncated;
        return $parsed;
    }

    private static function decodeContent(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content;
        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded)) {
            return [
                'overview' => trim($content),
                'key_points' => [],
                'sections' => [],
                'takeaways' => [],
                'action_items' => [],
            ];
        }
        return [
            'overview' => (string) ($decoded['overview'] ?? ''),
            'key_points' => array_values(array_map('strval', $decoded['key_points'] ?? [])),
            'sections' => is_array($decoded['sections'] ?? null) ? $decoded['sections'] : [],
            'takeaways' => array_values(array_map('strval', $decoded['takeaways'] ?? [])),
            'action_items' => array_values(array_map('strval', $decoded['action_items'] ?? [])),
        ];
    }
}
