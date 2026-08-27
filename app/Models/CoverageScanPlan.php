<?php

namespace App\Models;

use App\Enums\ScanCadence;
use App\Models\Concerns\BelongsToSite;
use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An operator's coverage-scan schedule for one GBP {@see Location}: which keywords to scan its served towns
 * for, how often ({@see ScanCadence}), and when it next runs. One plan per location. The daily
 * `launchpad:coverage-run-due` command dispatches the queued scans for due plans and advances `next_run_at`.
 *
 * @property string $id
 * @property string $site_id
 * @property string $location_id
 * @property list<string> $keyword_ids
 * @property ScanCadence $cadence
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 */
class CoverageScanPlan extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'keyword_ids' => 'array',
            'cadence' => ScanCadence::class,
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** The GBP location this plan scans (tenant-scope dropped — operator context crosses tenants). */
    public function location(): ?Location
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->site_id)
            ->whereKey($this->location_id)
            ->first();
    }

    /**
     * The keywords this plan will scan (in stored order), tenant-scope dropped.
     *
     * @return Collection<int, Keyword>
     */
    public function keywords(): Collection
    {
        $ids = $this->keyword_ids ?: [];
        if ($ids === []) {
            return new Collection;
        }

        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->site_id)
            ->whereIn('id', $ids)
            ->get();
    }

    /** Due to run: enabled, has a next-run time, and it has passed. */
    public function isDue(?Carbon $now = null): bool
    {
        return $this->enabled
            && $this->next_run_at !== null
            && $this->next_run_at->lte($now ?? Carbon::now());
    }
}
