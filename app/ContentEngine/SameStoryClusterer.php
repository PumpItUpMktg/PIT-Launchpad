<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;

/**
 * Collapses multi-outlet coverage of one event into a single candidate, by
 * shared topic or title-token overlap.
 */
class SameStoryClusterer
{
    public function __construct(private readonly float $threshold = 0.6) {}

    /**
     * @param  list<NewsItem>  $items
     * @return list<NewsCluster>
     */
    public function cluster(array $items): array
    {
        /** @var list<array{rep: NewsItem, members: list<NewsItem>}> $groups */
        $groups = [];

        foreach ($items as $item) {
            $placed = false;

            foreach ($groups as $index => $group) {
                $sameTopic = $item->topic !== null && $item->topic === $group['rep']->topic;

                if ($sameTopic || $this->titleSimilarity($item->title, $group['rep']->title) >= $this->threshold) {
                    $groups[$index]['members'][] = $item;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $groups[] = ['rep' => $item, 'members' => [$item]];
            }
        }

        return array_map(fn (array $g) => new NewsCluster($g['rep'], $g['members']), $groups);
    }

    private function titleSimilarity(string $a, string $b): float
    {
        $tokensA = $this->tokens($a);
        $tokensB = $this->tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique([...$tokensA, ...$tokensB]));

        return $intersection / $union;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $tokens = array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [],
            fn (string $t) => mb_strlen($t) > 2,
        );

        return array_values(array_unique($tokens));
    }
}
