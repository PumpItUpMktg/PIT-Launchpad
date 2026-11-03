<?php

namespace App\ContentEngine\Questions;

/**
 * One question topic: the lead phrasing (the variant with the most impressions) plus the near-identical
 * phrasings that cluster with it ("how long do sump pumps last" / "how long does a sump pump last"), with
 * their demand summed so the topic is judged once, not once per wording.
 */
final class QuestionCluster
{
    /** @param  list<QuestionStat>  $variants  every phrasing, lead first */
    public function __construct(
        public readonly QuestionStat $lead,
        public readonly array $variants,
    ) {}

    public function query(): string
    {
        return $this->lead->query;
    }

    public function impressions(): int
    {
        return array_sum(array_map(fn (QuestionStat $v): int => $v->impressions, $this->variants));
    }

    public function clicks(): int
    {
        return array_sum(array_map(fn (QuestionStat $v): int => $v->clicks, $this->variants));
    }

    /** Impression-weighted position across the variants (null when no variant carried one). */
    public function position(): ?float
    {
        $num = 0.0;
        $den = 0;
        foreach ($this->variants as $v) {
            if ($v->position === null) {
                continue;
            }
            $num += $v->position * $v->impressions;
            $den += $v->impressions;
        }

        return $den > 0 ? round($num / $den, 1) : null;
    }

    /** @return list<string> the other phrasings, most impressions first */
    public function otherPhrasings(): array
    {
        return array_map(fn (QuestionStat $v): string => $v->query, array_slice($this->variants, 1));
    }

    /** The URL carrying the most impressions across the cluster, null when none. */
    public function topUrl(): ?string
    {
        $totals = [];
        foreach ($this->variants as $v) {
            foreach ($v->urls as $url => $u) {
                $totals[$url] = ($totals[$url] ?? 0) + $u['impressions'];
            }
        }
        if ($totals === []) {
            return null;
        }
        arsort($totals);

        return (string) array_key_first($totals);
    }

    /** Impression-weighted position of {@see topUrl()} across the cluster, null when unknown. */
    public function topUrlPosition(): ?float
    {
        $url = $this->topUrl();
        if ($url === null) {
            return null;
        }
        $num = 0.0;
        $den = 0;
        foreach ($this->variants as $v) {
            $u = $v->urls[$url] ?? null;
            if ($u === null || $u['position'] === null) {
                continue;
            }
            $num += $u['position'] * $u['impressions'];
            $den += $u['impressions'];
        }

        return $den > 0 ? round($num / $den, 1) : null;
    }

    /**
     * The token set two phrasings are compared on: lowercase words minus filler (articles, auxiliaries,
     * pronouns) with a trailing plural "s" dropped — so "do sump pumps" and "does a sump pump" agree.
     *
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        static $stop = ['a', 'an', 'the', 'my', 'your', 'our', 'i', 'you', 'we', 'it', 'its', 'is', 'are', 'do', 'does',
            'did', 'be', 'to', 'of', 'in', 'on', 'for', 'at', 'and', 'or', 'with', 'can', 'should', 'will', 'would',
            'could', 'there', 'this', 'that', 'have', 'has', 'get', 'need', 'needs', 'if', 'me', 'up'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($query)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if ($w === '' || in_array($w, $stop, true)) {
                continue;
            }
            if (strlen($w) > 3 && str_ends_with($w, 's') && ! str_ends_with($w, 'ss')) {
                $w = substr($w, 0, -1);
            }
            $out[$w] = true;
        }

        return array_keys($out);
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $inter / $union;
    }

    /**
     * Greedy clustering, most impressions first: a phrasing joins the first cluster whose lead it matches
     * at or above `$threshold` Jaccard on {@see tokens()}, else starts its own.
     *
     * @param  list<QuestionStat>  $stats
     * @return list<self> most impressions first
     */
    public static function cluster(array $stats, float $threshold = 0.75): array
    {
        usort($stats, fn (QuestionStat $a, QuestionStat $b): int => [$b->impressions, $b->clicks] <=> [$a->impressions, $a->clicks]);

        /** @var list<array{lead: QuestionStat, tokens: list<string>, variants: list<QuestionStat>}> $groups */
        $groups = [];
        foreach ($stats as $stat) {
            $tokens = self::tokens($stat->query);
            foreach ($groups as &$group) {
                if (self::jaccard($group['tokens'], $tokens) >= $threshold) {
                    $group['variants'][] = $stat;

                    continue 2;
                }
            }
            unset($group);
            $groups[] = ['lead' => $stat, 'tokens' => $tokens, 'variants' => [$stat]];
        }

        $clusters = array_map(fn (array $g): self => new self($g['lead'], $g['variants']), $groups);
        usort($clusters, fn (self $a, self $b): int => [$b->impressions(), $b->clicks()] <=> [$a->impressions(), $a->clicks()]);

        return $clusters;
    }
}
