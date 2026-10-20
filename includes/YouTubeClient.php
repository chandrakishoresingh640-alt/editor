<?php
declare(strict_types=1);

final class YouTubeClient
{
    public static function videoId(string $input): string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 500) {
            throw new AppException('Enter a YouTube video URL.', 422, 'bad_url');
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }
        $parts = parse_url($input);
        if (!is_array($parts)) {
            throw new AppException('That does not look like a YouTube URL.', 422, 'bad_url');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new AppException('Only http and https YouTube links are accepted.', 422, 'bad_url');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $allowed = ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'youtube-nocookie.com'];
        if (!in_array($host, $allowed, true)) {
            throw new AppException('That URL is not a YouTube link.', 422, 'bad_url');
        }
        $id = '';
        if ($host === 'youtu.be') {
            $id = explode('/', trim((string) ($parts['path'] ?? ''), '/'))[0] ?? '';
        } else {
            $path = trim((string) ($parts['path'] ?? ''), '/');
            foreach (['embed/', 'shorts/', 'live/', 'v/'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $id = explode('/', substr($path, strlen($prefix)))[0] ?? '';
                }
            }
            if ($id === '' && isset($parts['query'])) {
                parse_str((string) $parts['query'], $query);
                $id = (string) ($query['v'] ?? '');
            }
        }
        $id = preg_replace('/[^A-Za-z0-9_-].*$/', '', $id) ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new AppException('A video ID could not be read from that URL.', 422, 'bad_url');
        }
        return $id;
    }

    public static function metadata(string $videoId): array
    {
        $base = [
            'video_id' => $videoId,
            'watch_url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'source' => null,
            'title' => null,
            'author' => null,
            'thumbnail' => null,
            'description' => null,
            'published' => null,
            'duration' => null,
            'note' => null,
        ];
        $key = trim((string) config('youtube_api_key'));
        if ($key !== '') {
            $url = 'https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id='
                . rawurlencode($videoId) . '&key=' . rawurlencode($key);
            $response = http_json('GET', $url, [], null, 15);
            $item = $response['json']['items'][0] ?? null;
            if ($response['status'] === 200 && is_array($item)) {
                $snippet = $item['snippet'] ?? [];
                $base['source'] = 'youtube-data-api';
                $base['title'] = (string) ($snippet['title'] ?? '');
                $base['author'] = (string) ($snippet['channelTitle'] ?? '');
                $base['description'] = (string) ($snippet['description'] ?? '');
                $base['published'] = (string) ($snippet['publishedAt'] ?? '');
                $base['thumbnail'] = (string) ($snippet['thumbnails']['medium']['url'] ?? $snippet['thumbnails']['default']['url'] ?? '');
                $base['duration'] = (string) ($item['contentDetails']['duration'] ?? '');
                return $base;
            }
            $base['note'] = 'The YouTube Data API did not return this video. It may be private, deleted, or the API key may be restricted.';
        }
        $oembed = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($base['watch_url']);
        try {
            $response = http_json('GET', $oembed, [], null, 15);
        } catch (AppException $e) {
            $base['note'] = $base['note'] ?: 'Public video details could not be loaded.';
            return $base;
        }
        if ($response['status'] === 200 && is_array($response['json'])) {
            $base['source'] = $base['source'] ?? 'oembed';
            $base['title'] = $base['title'] ?: (string) ($response['json']['title'] ?? '');
            $base['author'] = $base['author'] ?: (string) ($response['json']['author_name'] ?? '');
            $base['thumbnail'] = $base['thumbnail'] ?: (string) ($response['json']['thumbnail_url'] ?? '');
            if ($key === '') {
                $base['note'] = 'Showing public oEmbed details. Add a YouTube Data API key for description and duration. Original scripts and captions are not included.';
            }
            return $base;
        }
        $base['note'] = $base['note'] ?: 'No public details were returned. The video may be private, unavailable, or restricted.';
        return $base;
    }

    public static function ownedCaptions(string $videoId, string $accessToken): array
    {
        $list = http_json(
            'GET',
            'https://www.googleapis.com/youtube/v3/captions?part=snippet&videoId=' . rawurlencode($videoId),
            ['Authorization: Bearer ' . $accessToken],
            null,
            20
        );
        if ($list['status'] === 401) {
            throw new AppException('The Google sign-in expired. Connect again and retry.', 401, 'google_auth');
        }
        if ($list['status'] === 403) {
            throw new AppException('YouTube refused the caption request. The official captions API only returns tracks for videos owned by the connected channel.', 403, 'captions_forbidden');
        }
        if ($list['status'] !== 200) {
            throw new AppException('YouTube did not return a caption list for this video.', 502, 'captions');
        }
        $items = $list['json']['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new AppException('No caption track was found for this video on the connected channel. You can paste a transcript instead.', 404, 'no_captions');
        }
        $chosen = $items[0];
        foreach ($items as $item) {
            $lang = (string) ($item['snippet']['language'] ?? '');
            if ($lang === 'en' || str_starts_with($lang, 'en-')) {
                $chosen = $item;
                break;
            }
        }
        $captionId = (string) ($chosen['id'] ?? '');
        if ($captionId === '') {
            throw new AppException('The caption track did not include an identifier.', 502, 'captions');
        }
        $download = http_request(
            'GET',
            'https://www.googleapis.com/youtube/v3/captions/' . rawurlencode($captionId) . '?tfmt=vtt',
            ['Authorization: Bearer ' . $accessToken],
            null,
            30
        );
        if ($download['status'] === 403) {
            throw new AppException('YouTube refused to download that caption track. This usually means the connected account does not own the video.', 403, 'captions_forbidden');
        }
        if ($download['status'] !== 200) {
            throw new AppException('The caption track could not be downloaded.', 502, 'captions');
        }
        $text = self::vttToText($download['body']);
        if (trim($text) === '') {
            throw new AppException('The caption track was empty.', 422, 'empty');
        }
        return [
            'text' => $text,
            'kind' => 'caption-transcript',
            'language' => (string) ($chosen['snippet']['language'] ?? ''),
            'label' => 'Caption transcript from a video owned by the connected YouTube channel. This is not the unpublished original script.',
        ];
    }

    public static function vttToText(string $vtt): string
    {
        $lines = preg_split('/\R/', $vtt) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'WEBVTT') || str_starts_with($line, 'NOTE') || str_contains($line, '-->')) {
                continue;
            }
            if (preg_match('/^\d+$/', $line)) {
                continue;
            }
            $line = preg_replace('/<[^>]+>/', '', $line) ?? $line;
            if ($line !== '' && (end($kept) !== $line)) {
                $kept[] = $line;
            }
        }
        return trim(implode("\n", $kept));
    }
}
