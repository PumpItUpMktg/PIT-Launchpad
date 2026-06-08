<?php

namespace App\SiloCreator;

/**
 * Small text helper: tokenizes phrases into salient, de-duplicated terms,
 * dropping stop words and short tokens. Used to seed rule_sets.
 */
final class Terms
{
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'your', 'our', 'you', 'are', 'from', 'that',
        'this', 'has', 'have', 'will', 'can', 'how', 'why', 'what', 'when', 'who',
        'a', 'an', 'of', 'to', 'in', 'on', 'is', 'it', 'or', 'my', 'we', 'us',
    ];

    /**
     * @param  iterable<string>  $phrases
     * @return list<string>
     */
    public static function fromPhrases(iterable $phrases): array
    {
        $terms = [];

        foreach ($phrases as $phrase) {
            foreach (preg_split('/[^a-z0-9]+/', mb_strtolower((string) $phrase)) ?: [] as $token) {
                if (mb_strlen($token) > 2 && ! in_array($token, self::STOP_WORDS, true)) {
                    $terms[$token] = true;
                }
            }
        }

        return array_keys($terms);
    }
}
