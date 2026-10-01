<?php

namespace App\TownPages;

/**
 * The guard against the doorway pattern: two drafted sections for the same keyword on different towns must
 * differ by more than the town's name. Both bodies are lower-cased, their town names blanked, and compared
 * as word trigrams (Jaccard); above the configured overlap the new section is refused and the page keeps
 * none rather than a templated one.
 */
final class SectionUniqueness
{
    /**
     * @param  list<array{body: string, town: string}>  $others  the same keyword's sections on other towns
     * @return array{ok: bool, similarity: float}
     */
    public static function check(string $body, string $town, array $others, ?float $max = null): array
    {
        $max ??= (float) config('launchpad.town_pages.priority.max_similarity', 0.5);
        $mine = self::trigrams($body, [$town]);
        $worst = 0.0;
        foreach ($others as $other) {
            $theirs = self::trigrams((string) $other['body'], [$town, (string) $other['town']]);
            $worst = max($worst, self::jaccard($mine, $theirs));
        }

        return ['ok' => $worst <= $max, 'similarity' => round($worst, 3)];
    }

    /**
     * @param  list<string>  $towns
     * @return array<string, true>
     */
    private static function trigrams(string $text, array $towns): array
    {
        $text = mb_strtolower(strip_tags($text));
        foreach ($towns as $town) {
            $town = trim(mb_strtolower($town));
            if ($town !== '') {
                $text = str_replace($town, ' ', $text);
            }
        }
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grams = [];
        for ($i = 0, $n = count($words); $i + 2 < $n; $i++) {
            $grams[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $grams;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $both = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $both;

        return $union === 0 ? 0.0 : $both / $union;
    }
}
