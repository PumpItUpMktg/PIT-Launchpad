<?php

namespace App\Operator\Coverage;

use App\Models\Keyword;

/**
 * The §5 two-lane position picture for one keyword: the latest organic standing,
 * the per-market local-pack standings, a cannibalization flag, and the
 * refresh-ROI markers (RefreshEvent count + the organic rank series the UI
 * overlays them on).
 */
final class KeywordStandings
{
    /**
     * @param  list<array{market_id: string|null, market_name: string, rank: int|null, captured_at: string|null}>  $localByMarket
     * @param  list<array{captured_at: string, rank: int|null}>  $organicSeries
     */
    public function __construct(
        public readonly Keyword $keyword,
        public readonly ?int $organicRank,
        public readonly ?string $organicUrl,
        public readonly ?string $capturedAt,
        public readonly array $localByMarket,
        public readonly bool $cannibalizing,
        public readonly int $refreshCount,
        public readonly array $organicSeries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'keyword_id' => $this->keyword->id,
            'query' => $this->keyword->query,
            'organic_rank' => $this->organicRank,
            'organic_url' => $this->organicUrl,
            'captured_at' => $this->capturedAt,
            'local_by_market' => $this->localByMarket,
            'cannibalizing' => $this->cannibalizing,
            'refresh_count' => $this->refreshCount,
            'organic_series' => $this->organicSeries,
        ];
    }
}
