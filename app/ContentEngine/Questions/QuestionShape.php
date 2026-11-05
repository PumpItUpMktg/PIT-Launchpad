<?php

namespace App\ContentEngine\Questions;

/**
 * Is a search query a QUESTION a homeowner asked, rather than a keyword? Generic, tenant-agnostic rules:
 * it starts with a question word, or carries a cost / comparison intent, has at least `minWords` words,
 * and does not name the tenant's brand (a brand query is navigation, never a topic). The brand is read
 * from the site, never hardcoded.
 */
final class QuestionShape
{
    /** @var list<string> */
    public const QUESTION_WORDS = [
        'how', 'what', 'why', 'when', 'which', 'where', 'who', 'does', 'do', 'can', 'should', 'is', 'are',
        'will', 'did', 'could', 'would', 'whats', "what's", 'whos', "who's",
    ];

    /** Intent markers that make a keyword a question even without a leading question word. */
    public const INTENT_MARKERS = ['cost', 'price', 'how much', ' vs ', ' vs. ', 'versus', 'worth it'];

    /**
     * The SQL LIKE prefilter mirroring {@see isQuestion()} — prunes the store before the PHP pass reads it.
     *
     * @return list<string>
     */
    public static function likePatterns(): array
    {
        $patterns = array_map(fn (string $w): string => $w.' %', self::QUESTION_WORDS);
        foreach (self::INTENT_MARKERS as $marker) {
            $patterns[] = '%'.trim($marker).'%';
        }

        return $patterns;
    }

    /**
     * @param  list<string>  $brandNames  the tenant's brand (and domain host) — a query carrying one is excluded
     */
    public static function isQuestion(string $query, array $brandNames = [], int $minWords = 3): bool
    {
        $q = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? $query));
        if ($q === '') {
            return false;
        }
        $words = explode(' ', $q);
        if (count($words) < max(1, $minWords)) {
            return false;
        }

        $key = self::nameKey($q);
        foreach ($brandNames as $brand) {
            $brandKey = self::nameKey($brand);
            if ($brandKey !== '' && str_contains($key, $brandKey)) {
                return false;
            }
        }

        $first = rtrim($words[0], '?,.');
        if (in_array($first, self::QUESTION_WORDS, true)) {
            return true;
        }
        $padded = ' '.$q.' ';
        foreach (self::INTENT_MARKERS as $marker) {
            if (str_contains($padded, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** Letters and digits only, lowercase — "Sump Pump Gurus" and "sumppumpgurus.com" both key to the brand. */
    public static function nameKey(string $value): string
    {
        $value = preg_replace('/^(https?:\/\/)?(www\.)?/i', '', trim($value)) ?? $value;
        $value = preg_replace('/\.(com|net|org|co|us|biz|info)(\/.*)?$/i', '', $value) ?? $value;

        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }
}
