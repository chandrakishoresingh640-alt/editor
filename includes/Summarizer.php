<?php
declare(strict_types=1);

final class Summarizer
{
    private const STOP = [
        'a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'when', 'while', 'of', 'to', 'for',
        'in', 'on', 'at', 'by', 'with', 'from', 'into', 'about', 'as', 'is', 'are', 'was', 'were', 'be',
        'been', 'being', 'it', 'this', 'that', 'these', 'those', 'i', 'you', 'we', 'they', 'he', 'she',
        'them', 'his', 'her', 'their', 'our', 'your', 'my', 'not', 'no', 'yes', 'do', 'did', 'does',
        'have', 'has', 'had', 'will', 'just', 'than', 'so', 'too', 'very', 'can', 'could', 'would',
        'should', 'there', 'here', 'what', 'which', 'who', 'whom', 'how', 'why', 'also', 'its',
    ];

    public static function extractive(string $text, string $length): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? '');
        if ($text === '') {
            throw new AppException('There is no transcript text to summarize.', 422, 'empty');
        }
        if (strlen($text) > 200000) {
            $text = substr($text, 0, 200000);
        }
        $sentences = self::sentences($text);
        if ($sentences === []) {
            throw new AppException('There is no transcript text to summarize.', 422, 'empty');
        }
        $scored = self::score($sentences);
        $limit = match ($length) {
            'short' => 3,
            'detailed' => min(14, max(8, (int) ceil(count($sentences) * 0.35))),
            default => 6,
        };
        $picked = array_slice($scored, 0, min($limit, count($scored)));
        usort($picked, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        $overview = implode(' ', array_map(static fn (array $row): string => $row['text'], $picked));
        $keyPoints = array_map(static fn (array $row): string => $row['text'], array_slice($scored, 0, min(5, count($scored))));
        $sections = self::sections($sentences, $scored);
        $actions = self::actions($sentences);
        return [
            'method' => 'extractive',
            'overview' => $overview,
            'key_points' => $keyPoints,
            'sections' => $sections,
            'takeaways' => array_slice($keyPoints, 0, 4),
            'action_items' => $actions,
            'sentence_count' => count($sentences),
        ];
    }

    /** @return array<int,string> */
    private static function sentences(string $text): array
    {
        $chunks = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“])/', $text) ?: [];
        if (count($chunks) < 2) {
            $chunks = preg_split('/\n+/', $text) ?: [];
        }
        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');
            if (strlen($chunk) >= 20) {
                $out[] = $chunk;
            }
        }
        if ($out === [] && trim($text) !== '') {
            $out[] = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        }
        return $out;
    }

    /**
     * @param array<int,string> $sentences
     * @return array<int,array{index:int,text:string,score:float}>
     */
    private static function score(array $sentences): array
    {
        $freq = [];
        foreach ($sentences as $sentence) {
            foreach (self::words($sentence) as $word) {
                $freq[$word] = ($freq[$word] ?? 0) + 1;
            }
        }
        $scored = [];
        foreach ($sentences as $index => $sentence) {
            $words = self::words($sentence);
            $score = 0.0;
            foreach ($words as $word) {
                $score += $freq[$word] ?? 0;
            }
            if ($words !== []) {
                $score /= sqrt(count($words));
            }
            $scored[] = ['index' => $index, 'text' => $sentence, 'score' => $score];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $scored;
    }

    /** @return array<int,string> */
    private static function words(string $sentence): array
    {
        $raw = strtolower($sentence);
        preg_match_all("/[a-z0-9']{3,}/", $raw, $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            if (!in_array($word, self::STOP, true)) {
                $words[] = $word;
            }
        }
        return $words;
    }

    /**
     * @param array<int,string> $sentences
     * @param array<int,array{index:int,text:string,score:float}> $scored
     * @return array<int,array{title:string,summary:string}>
     */
    private static function sections(array $sentences, array $scored): array
    {
        $count = count($sentences);
        $groups = min(4, max(1, (int) ceil($count / 6)));
        $size = (int) ceil($count / $groups);
        $best = [];
        foreach ($scored as $row) {
            $best[$row['index']] = $row['score'];
        }
        $sections = [];
        for ($g = 0; $g < $groups; $g++) {
            $slice = array_slice($sentences, $g * $size, $size, true);
            if ($slice === []) {
                continue;
            }
            $winner = null;
            $winnerScore = -1;
            foreach ($slice as $index => $sentence) {
                $score = $best[$index] ?? 0;
                if ($score > $winnerScore) {
                    $winnerScore = $score;
                    $winner = $sentence;
                }
            }
            $first = (string) reset($slice);
            $title = self::title($first, $g + 1);
            $sections[] = ['title' => $title, 'summary' => (string) $winner];
        }
        return $sections;
    }

    private static function title(string $sentence, int $number): string
    {
        $words = preg_split('/\s+/', trim($sentence)) ?: [];
        $title = implode(' ', array_slice($words, 0, 7));
        if (strlen($title) > 72) {
            $title = substr($title, 0, 69) . '…';
        }
        return 'Part ' . $number . ' — ' . $title;
    }

    /** @param array<int,string> $sentences */
    private static function actions(array $sentences): array
    {
        $actions = [];
        foreach ($sentences as $sentence) {
            if (preg_match('/\b(should|must|need to|needs to|try to|make sure|remember to|step|action|please|start by|be sure)\b/i', $sentence)) {
                $actions[] = $sentence;
            }
            if (count($actions) >= 6) {
                break;
            }
        }
        return $actions;
    }
}
