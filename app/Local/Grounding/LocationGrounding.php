<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trade-keyed local-facts enrichment for a location page. The tenant's trade picks which sources
 * fire (config launchpad.grounding.trade_map); results cache on the Location record
 * (grounding_cache: {facts, sources, fetched_at}) so regeneration inside the staleness window
 * (default 90 days) never refetches. Every provider failure is skip-log-continue — grounding is
 * NEVER a generation blocker. Drafter input only; never rendered as live page widgets.
 */
final class LocationGrounding
{
    /**
     * @return array{facts: list<string>, sources: list<string>, fetched_at: string}
     */
    public function for(Location $location, ?string $trade, bool $force = false): array
    {
        $cache = is_array($location->grounding_cache) ? $location->grounding_cache : null;
        if (! $force && $cache !== null && $this->fresh($cache)) {
            /** @var array{facts: list<string>, sources: list<string>, fetched_at: string} $cache */
            return $cache;
        }

        $facts = [];
        $sources = [];
        foreach ($this->sourcesForTrade($trade) as $key => $class) {
            try {
                /** @var GroundingProvider $provider */
                $provider = app($class);
                $result = $provider->fetch($location);
            } catch (Throwable $e) {
                Log::warning('Grounding source failed — skipped.', ['source' => $key, 'error' => $e->getMessage()]);

                continue;
            }
            foreach ($result['facts'] as $fact) {
                $facts[] = $fact;
            }
            if ($result['facts'] !== []) {
                $sources[] = $result['source'];
            }
        }

        $fresh = ['facts' => $facts, 'sources' => $sources, 'fetched_at' => now()->toIso8601String()];
        $location->forceFill(['grounding_cache' => $fresh])->save();

        return $fresh;
    }

    /**
     * The providers the tenant's trade fires — keyword-matched against the trade_map keys, so the
     * free-text trade ("Basement waterproofing & sump pumps") lands on 'waterproofing'.
     *
     * @return array<string, class-string<GroundingProvider>>
     */
    private function sourcesForTrade(?string $trade): array
    {
        $map = (array) config('launchpad.grounding.trade_map', []);
        $sources = (array) config('launchpad.grounding.sources', []);
        $trade = mb_strtolower((string) $trade);

        $keys = $map['_default'] ?? [];
        foreach ($map as $tradeKey => $tradeSources) {
            if ($tradeKey !== '_default' && str_contains($trade, str_replace('_', ' ', $tradeKey))) {
                $keys = $tradeSources;

                break;
            }
        }

        $out = [];
        foreach ((array) $keys as $key) {
            if (isset($sources[$key])) {
                $out[$key] = $sources[$key];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $cache */
    private function fresh(array $cache): bool
    {
        $fetchedAt = $cache['fetched_at'] ?? null;
        if (! is_string($fetchedAt) || $fetchedAt === '') {
            return false;
        }

        return Carbon::parse($fetchedAt)->diffInDays(now()) < (int) config('launchpad.grounding.stale_days', 90);
    }
}
