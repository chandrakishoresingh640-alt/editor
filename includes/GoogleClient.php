<?php
declare(strict_types=1);

final class GoogleClient
{
    public static function configured(): bool
    {
        return trim((string) config('google_client_id')) !== ''
            && trim((string) config('google_client_secret')) !== ''
            && trim((string) config('google_redirect_uri')) !== '';
    }

    public static function authorizationUrl(string $purpose): string
    {
        if (!self::configured()) {
            throw new AppException('Google sign-in is not configured on this server.', 409, 'google_unconfigured');
        }
        $purpose = $purpose === 'youtube' ? 'youtube' : 'docs';
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_state'] = $state;
        $_SESSION['google_purpose'] = $purpose;
        $scope = $purpose === 'youtube'
            ? 'https://www.googleapis.com/auth/youtube.force-ssl'
            : 'https://www.googleapis.com/auth/documents';
        $query = http_build_query([
            'client_id' => (string) config('google_client_id'),
            'redirect_uri' => (string) config('google_redirect_uri'),
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    public static function handleCallback(string $code, string $state): string
    {
        $known = (string) ($_SESSION['google_state'] ?? '');
        if ($known === '' || !hash_equals($known, $state)) {
            throw new AppException('The Google sign-in response did not match this session. Start again.', 400, 'google_state');
        }
        unset($_SESSION['google_state']);
        $response = http_request(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'code' => $code,
                'client_id' => (string) config('google_client_id'),
                'client_secret' => (string) config('google_client_secret'),
                'redirect_uri' => (string) config('google_redirect_uri'),
                'grant_type' => 'authorization_code',
            ]),
            20
        );
        $json = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($json) || empty($json['access_token'])) {
            throw new AppException('Google did not complete sign-in.', 502, 'google_token');
        }
        $purpose = (string) ($_SESSION['google_purpose'] ?? 'docs');
        $_SESSION['google_token'] = [
            'access_token' => (string) $json['access_token'],
            'purpose' => $purpose,
            'expires' => time() + (int) ($json['expires_in'] ?? 3600),
        ];
        return $purpose;
    }

    public static function token(string $purpose): string
    {
        $stored = $_SESSION['google_token'] ?? null;
        if (!is_array($stored) || ($stored['purpose'] ?? '') !== $purpose || (int) ($stored['expires'] ?? 0) < time() + 30) {
            throw new AppException('Connect Google before using this step.', 401, 'google_auth');
        }
        return (string) $stored['access_token'];
    }

    public static function createDocument(string $title, string $text): array
    {
        $token = self::token('docs');
        $created = http_json(
            'POST',
            'https://docs.googleapis.com/v1/documents',
            ['Authorization: Bearer ' . $token],
            ['title' => mb_substr($title, 0, 120)],
            20
        );
        if ($created['status'] === 401) {
            throw new AppException('The Google sign-in expired. Connect again and retry.', 401, 'google_auth');
        }
        if ($created['status'] !== 200 || empty($created['json']['documentId'])) {
            throw new AppException('Google Docs did not create a document.', 502, 'google_docs');
        }
        $id = (string) $created['json']['documentId'];
        $clipped = $text;
        if (strlen($clipped) > 800000) {
            $clipped = substr($clipped, 0, 800000);
        }
        $update = http_json(
            'POST',
            'https://docs.googleapis.com/v1/documents/' . rawurlencode($id) . ':batchUpdate',
            ['Authorization: Bearer ' . $token],
            ['requests' => [['insertText' => ['location' => ['index' => 1], 'text' => $clipped]]]],
            30
        );
        if ($update['status'] !== 200) {
            throw new AppException('The document was created but the text could not be inserted.', 502, 'google_docs');
        }
        return [
            'document_id' => $id,
            'url' => 'https://docs.google.com/document/d/' . $id . '/edit',
        ];
    }
}
