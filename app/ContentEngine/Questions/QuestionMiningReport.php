<?php

namespace App\ContentEngine\Questions;

/**
 * What one mining pass found for a site — report-first: every count the operator needs to trust the top
 * list (how many queries were read, how many were question-shaped, which were dropped as machine traffic
 * and by which rule) travels with the clusters themselves.
 */
final class QuestionMiningReport
{
    /**
     * @param  list<QuestionCluster>  $clusters  every surviving topic, most impressions first
     * @param  list<array{query: string, reason: string, impressions: int, clicks: int, position: float|null}>  $botDropped
     */
    public function __construct(
        public readonly string $siteId,
        public readonly int $windowDays,
        public readonly string $from,
        public readonly string $to,
        public readonly int $queriesRead,
        public readonly int $questionShaped,
        public readonly array $botDropped,
        public readonly array $clusters,
    ) {}

    /** @return list<QuestionCluster> */
    public function top(int $n): array
    {
        return array_slice($this->clusters, 0, max(0, $n));
    }

    /** @return array<string, int> rule name => queries it dropped */
    public function botDroppedByRule(): array
    {
        $out = [];
        foreach ($this->botDropped as $row) {
            $out[$row['reason']] = ($out[$row['reason']] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }
}
