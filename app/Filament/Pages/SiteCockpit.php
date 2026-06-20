<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ContentReviewResource;
use App\Models\Site;
use App\Operator\PipelineMetrics;
use BackedEnum;
use Filament\Pages\Page;

/**
 * The per-site cockpit — the current pipeline dashboard scoped to ONE tenant (the drill-down
 * target from the admin overview cards). Every content metric (candidates, needs review,
 * approved-pending-publish, published this week, render/publish failed, the funnel, per-silo
 * volume) is filtered to a single site_id via the same {@see PipelineMetrics} service. No
 * aggregate version survives — these render only per-site. Counts link through to the actionable
 * work (the review queue), not dead numbers. Reached via `?site=`; not in the nav.
 *
 * @property-read array<string, int> $stats
 * @property-read array<string, int> $funnel
 * @property-read list<array{silo_id: string, silo_name: string, total: int}> $perSilo
 */
class SiteCockpit extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static bool $shouldRegisterNavigation = false; // drill-down only

    protected static ?string $slug = 'cockpit';

    protected string $view = 'filament.pages.site-cockpit';

    public ?string $siteId = null;

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('cockpit_site_id');
        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['cockpit_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function getTitle(): string
    {
        $site = $this->getSite();

        return $site !== null ? (string) $site->brand_name : 'Cockpit';
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function updatedSiteId(): void
    {
        session(['cockpit_site_id' => $this->siteId]);
    }

    /** @return array<string, int> */
    public function getStatsProperty(): array
    {
        return app(PipelineMetrics::class)->statCards($this->siteId);
    }

    /** @return array<string, int> */
    public function getFunnelProperty(): array
    {
        return app(PipelineMetrics::class)->funnel($this->siteId);
    }

    /** @return list<array{silo_id: string, silo_name: string, total: int}> */
    public function getPerSiloProperty(): array
    {
        return app(PipelineMetrics::class)->perSilo($this->siteId);
    }

    /** The actionable work-item target for a count click-through. */
    public function reviewUrl(): string
    {
        return ContentReviewResource::getUrl();
    }
}
