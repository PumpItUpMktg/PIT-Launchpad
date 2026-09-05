<?php

namespace App\Filament\Pages\Citations;

use App\Citations\Ui\CitationReport;
use App\Citations\Ui\CitationReportData;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Citations · Report (§ Citations UI, PR E) — the client-readable citation report for one location. Same
 * records as the operator workspace, translated to a client reading level (correct / wrong / being-added /
 * available, plus the plain-language corrections). Reached from the workspace; hidden from the sidebar. Built
 * standalone so it can be lifted into the client /portal later without a rewrite.
 *
 * @property-read CitationReportData|null $report
 */
class CitationsReport extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $slug = 'citations/report';

    protected string $view = 'filament.citations.report';

    public ?string $locationId = null;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public function mount(?string $location = null): void
    {
        $requested = $location ?? request()->query('location');
        $this->locationId = is_string($requested) ? $requested : null;
        // A provided location id that doesn't resolve within the locked tenant is not found (foreign or
        // stale) — 404 rather than rebind or render another tenant's report (tenant-lock, shape B).
        abort_if($this->locationId !== null && $this->getLocation() === null, 404);
    }

    /**
     * Resolved ONLY within the locked tenant ({@see ActiveTenant}). A foreign `?location=` returns null and
     * never rebinds CurrentSite — that set()-from-record was the shape-B lock-override vector. CurrentSite is
     * already the locked tenant (ResolveCurrentSite), so no rebind is needed for the legitimate case.
     */
    public function getLocation(): ?Location
    {
        $siteId = app(ActiveTenant::class)->id();
        if ($this->locationId === null || $siteId === null) {
            return null;
        }

        return Location::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->find($this->locationId);
    }

    public function getReportProperty(): ?CitationReportData
    {
        $location = $this->getLocation();
        if ($location === null) {
            return null;
        }

        return app(CitationReport::class)->forLocation($location);
    }
}
