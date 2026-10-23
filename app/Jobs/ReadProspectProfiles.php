<?php

namespace App\Jobs;

use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Links\Profile\ProspectProfiler;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read a batch of profiles for one site's lane on the worker (§ Links) — the "Read profiles" click.
 * Unread first, most local first, bounded per click. Works in chunks: each job reads up to CHUNK rows
 * (well inside the queue's retry window) and re-dispatches itself for the rest, so a long batch never
 * runs past the worker's timeout. Already-read rows are skipped, so a double click costs nothing.
 */
class ReadProspectProfiles implements ShouldQueue
{
    use Queueable;

    public const DEFAULT_LIMIT = 40;

    public const CHUNK = 10;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $siteId, public readonly ?string $kind = null, public readonly int $limit = self::DEFAULT_LIMIT) {}

    /** @return list<LinkProspect> */
    public static function pending(string $siteId, ?string $kind, int $limit): array
    {
        $q = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)
            ->whereIn('status', LinkProspectStatus::openValues())
            ->whereIn('kind', array_map(fn (LinkProspectKind $k): string => $k->value, LinkProspectKind::lanes()))
            ->whereNull('profile_read_at');
        if ($kind !== null && LinkProspectKind::tryFrom($kind)?->isLane()) {
            $q->where('kind', $kind);
        }

        return $q->orderByDesc('locality')->orderByDesc('rank')->orderBy('domain')->limit(max(1, min(200, $limit)))->get()->all();
    }

    public function handle(ProspectProfiler $profiler): void
    {
        $take = max(1, min(self::CHUNK, $this->limit));
        $rows = self::pending($this->siteId, $this->kind, $take);
        foreach ($rows as $prospect) {
            $profiler->read($prospect);
        }
        $remaining = $this->limit - $take;
        if (count($rows) === $take && $remaining > 0 && self::pending($this->siteId, $this->kind, 1) !== []) {
            self::dispatch($this->siteId, $this->kind, $remaining);
        }
    }
}
