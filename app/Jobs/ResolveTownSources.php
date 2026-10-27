<?php

namespace App\Jobs;

use App\Locations\Sources\TownSourceResolver;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolve the official local sources for a site's towns on the worker (§ Locations): unresolved first,
 * the towns with a live page first, in self-chaining chunks so no job runs past the queue's retry window.
 */
class ResolveTownSources implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 15;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $siteId, public readonly int $limit = 60, public readonly bool $force = false, public readonly bool $revalidate = false) {}

    /** @return list<CoverageArea> */
    public static function pending(string $siteId, int $limit, bool $force = false): array
    {
        $q = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNotNull('state');
        if (! $force) {
            $q->whereNull('local_sources_resolved_at');
        }

        return $q->orderByDesc('page_selected')->orderBy('name')->limit(max(1, min(500, $limit)))->get()->all();
    }

    public function handle(TownSourceResolver $resolver): void
    {
        if ($this->revalidate) {
            // Cheap: re-check every resolved town against the current rules, re-search only the ones that lost a pick.
            foreach (self::resolved($this->siteId) as $area) {
                if ($resolver->revalidate($area)) {
                    $resolver->resolve($area->fresh() ?? $area);
                }
            }

            return;
        }
        $take = max(1, min(self::CHUNK, $this->limit));
        $rows = self::pending($this->siteId, $take, $this->force);
        foreach ($rows as $area) {
            $resolver->resolve($area);
        }
        $remaining = $this->limit - $take;
        if (count($rows) === $take && $remaining > 0 && self::pending($this->siteId, 1, false) !== []) {
            self::dispatch($this->siteId, $remaining, false);
        }
    }

    /** @return list<CoverageArea> every town already resolved */
    public static function resolved(string $siteId): array
    {
        return CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNotNull('local_sources_resolved_at')->orderBy('name')->get()->all();
    }
}
