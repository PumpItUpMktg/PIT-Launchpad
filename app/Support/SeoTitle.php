<?php

namespace App\Support;

/**
 * Normalizes an SEO title to the rules every drafted/published title must obey:
 * no publication/source name, no pipe-suffixed branding ("Headline | Brand"), no
 * attribution (that lives in the body), and a ~60-character cap for clean SERP
 * display. Pure + idempotent — a clean title is returned unchanged — so it is the
 * GUARANTEE applied at draft time AND at publish (a re-push normalizes existing
 * titles). The drafting prompt asks the model to comply; this enforces it.
 */
final class SeoTitle
{
    public const MAX_LENGTH = 60;

    public static function normalize(string $title, ?string $sourceName = null, int $maxLength = self::MAX_LENGTH): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $title));

        // Pipe-suffixed branding: "Headline | Brand" → "Headline".
        if (str_contains($title, '|')) {
            $title = trim((string) strstr($title, '|', true));
        }

        if ($sourceName !== null && trim($sourceName) !== '') {
            $title = self::stripSource($title, trim($sourceName));
        }

        return self::truncate($title, $maxLength);
    }

    /**
     * Remove the source/publication name where it appears as ATTRIBUTION — a
     * trailing/leading dash or colon, parentheses, or an "according to/via/from"
     * phrase. The bare name is not stripped from mid-sentence (it can be a
     * legitimate topic word), only these attribution positions.
     */
    private static function stripSource(string $title, string $source): string
    {
        $s = preg_quote($source, '/');
        $patterns = [
            '/\s*[-–—:]\s*'.$s.'\s*$/i',                              // trailing " - Source" / ": Source"
            '/^\s*'.$s.'\s*[-–—:|]\s*/i',                             // leading "Source - "
            '/\s*\(\s*'.$s.'\s*\)\s*/i',                              // " (Source)"
            '/\s*\b(?:according to|via|from|per|says|reports)\s+'.$s.'\b/i', // attribution phrase
        ];

        foreach ($patterns as $pattern) {
            $title = (string) preg_replace($pattern, ' ', $title);
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $title));
    }

    /**
     * Connectives, articles, prepositions and interrogatives that must never END a title — cutting at the
     * cap routinely lands on one ("…Chester County: What", "…before it"), leaving a dangling fragment that
     * then becomes the slug. When truncation strands one of these, it's dropped so the title reads as a
     * finished phrase.
     */
    private const DANGLING = [
        'a', 'an', 'the', 'and', 'or', 'but', 'nor', 'of', 'to', 'for', 'in', 'on', 'at', 'by', 'with',
        'from', 'as', 'is', 'are', 'was', 'were', 'be', 'that', 'this', 'these', 'those', 'your', 'you',
        'how', 'what', 'why', 'when', 'where', 'who', 'which', 'stop', 'before', 'after', 'into', 'over',
        'under', 'about', 'than', 'then', 'so', 'if', 'it', 'its', 'up', 'out', 'off', 'per', 'via', 'amid',
    ];

    /**
     * Cap the title without stranding a mid-phrase fragment. First preference: cut at the last CLAUSE
     * boundary (colon / dash / semicolon / comma) inside the window when it still keeps a substantial title
     * ("…County: What Homeowners…" → "…County"). Otherwise cut at a word boundary and drop any trailing
     * {@see self::DANGLING} words so it never ends on "What" / "before" / "to".
     */
    private static function truncate(string $title, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($title) <= $maxLength) {
            return $title;
        }

        $cut = mb_substr($title, 0, $maxLength);

        // Prefer the last clause boundary if it leaves at least half the cap — a natural, complete stop.
        if (preg_match_all('/[:;,–—]|\s[-|]\s/u', $cut, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $clause = rtrim(mb_strcut($cut, 0, (int) $last[1]));
            if (mb_strlen($clause) >= (int) ceil($maxLength / 2)) {
                return rtrim($clause, " \t-–—|:,;");
            }
        }

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        // Drop trailing dangling words ("…before it" → "…", "…County What" → "…County").
        $words = preg_split('/\s+/', trim($cut)) ?: [];
        while (count($words) > 1) {
            $tail = mb_strtolower(rtrim((string) end($words), '.,:;!?-–—|'));
            if (! in_array($tail, self::DANGLING, true)) {
                break;
            }
            array_pop($words);
        }

        return rtrim(implode(' ', $words), " \t-–—|:,;");
    }
}
