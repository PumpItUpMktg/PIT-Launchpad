<?php

namespace App\Filament\Pages\Citations;

use App\Citations\Ui\CitationReport;
use App\Citations\Ui\CitationReportData;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
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
        $loc = $this->getLocation();
        if ($loc !== null) {
            CurrentSite::set((string) $loc->site_id);
        }
    }

    public function getLocation(): ?Location
    {
        return $this->locationId === null
            ? null
            : Location::query()->withoutGlobalScope(SiteScope::class)->find($this->locationId);
    }

    public function getReportProperty(): ?CitationReportData
    {
        $location = $this->getLocation();
        if ($location === null) {
            return null;
        }
        CurrentSite::set((string) $location->site_id);

        return app(CitationReport::class)->forLocation($location);
    }
}
