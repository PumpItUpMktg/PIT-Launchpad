<?php

namespace App\Filament\Pages\Operate;

use App\Filament\Pages\GeoActivityConsole;
use App\Filament\Pages\GeoCoverageBoard;
use App\Filament\Pages\LocationDashboard;
use App\Filament\Pages\SetupHome;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\KeywordResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PublishedContentResource;
use App\Http\Middleware\EnsureTenantSelected;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Operator\SiteDashboard;
use BackedEnum;
use Filament\Pages\Page;

/**
 * The per-tenant operator dashboard (Relay 3 · PR 4) — the working home for the LOCKED tenant. Two
 * grids: eight metric cards (Search + speed + index + rankings, every one a persisted read via
 * {@see SiteDashboard} — zero live provider calls at render, acceptance 16), and eleven area cards
 * that navigate to the tenant's working surfaces.
 *
 * Scoped to the {@see ActiveTenant} lock — it renders for whichever tenant the operator selected in the
 * Lobby; there is no in-page site picker (the lock is the selection). This IS the operator's post-Lobby
 * landing: the cross-tenant OperateDashboard was retired into the Lobby (tenant-lock remediation), so the
 * only "Dashboard" is this per-tenant one.
 *
 * @property-read array<string, mixed> $metrics
 * @property-read list<array{label: string, url: string, desc: string, provisional?: bool}> $areas
 */
class TenantDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static bool $shouldRegisterNavigation = false; // reached from the Lobby / console header, not the sidebar

    // The panel landing (slug '/'): the operator's post-Lobby home is their locked tenant's dashboard. The
    // cross-tenant Overview that used to hold '/' is retired into the Lobby (tenant-lock remediation). The
    // route NAME stays `filament.admin.pages.tenant-dashboard` (Filament derives it from the class, not the
    // slug), so deep links and the gate keep resolving.
    protected static ?string $slug = '/';

    protected string $view = 'filament.operate.tenant-dashboard';

    public function getTitle(): string
    {
        $site = app(ActiveTenant::class)->site();

        return $site !== null ? (string) $site->brand_name : 'Dashboard';
    }

    public function getSite(): ?Site
    {
        return app(ActiveTenant::class)->site();
    }

    /**
     * The metric cards for the locked tenant — persisted reads only. Null (empty grid) when no tenant is
     * locked, which the hard gate ({@see EnsureTenantSelected}) normally prevents.
     *
     * @return array<string, mixed>
     */
    public function getMetricsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(SiteDashboard::class)->metrics($site);
    }

    /**
     * The eleven area cards — the tenant's working surfaces. Site-scoped pages carry `?site=`; resources
     * resolve the working tenant via the request site scope. Four targets are PROVISIONAL (marked): their
     * definitive homes land with the nav IA (PR 5, the 4-group / 24-surface cutover) — Jobs, Markets,
     * Measure, and Recover have no dedicated admin surface yet, so each points at its nearest neighbour.
     *
     * @return list<array{label: string, url: string, desc: string, provisional?: bool}>
     */
    public function getAreasProperty(): array
    {
        // No ?site= on any link — every target resolves the working tenant from the lock (ActiveTenant).
        // A ?site= arg is a dead reader and a live vector (a stale bookmark under a different lock).
        return [
            ['label' => 'Setup', 'url' => SetupHome::getUrl(), 'desc' => 'Intake, brand & launch steps'],
            ['label' => 'Posts', 'url' => OperateBlog::getUrl(), 'desc' => 'The blog / news pipeline'],
            ['label' => 'Pages', 'url' => PageResource::getUrl(), 'desc' => 'Service, location & core pages'],
            ['label' => 'Jobs', 'url' => LocationDashboard::getUrl(), 'desc' => 'Job-capture content', 'provisional' => true],
            ['label' => 'Reviews', 'url' => ContentReviewResource::getUrl(), 'desc' => 'The review & approve queue'],
            ['label' => 'Live', 'url' => PublishedContentResource::getUrl(), 'desc' => 'Published body of work'],
            ['label' => 'Markets', 'url' => GeoCoverageBoard::getUrl(), 'desc' => 'Territory & geo coverage', 'provisional' => true],
            ['label' => 'Targeting', 'url' => KeywordResource::getUrl(), 'desc' => 'Keyword targets & gaps'],
            ['label' => 'Measure', 'url' => GeoActivityConsole::getUrl(), 'desc' => 'Visibility & analytics', 'provisional' => true],
            ['label' => 'Settings', 'url' => ConnectionsResource::getUrl(), 'desc' => 'Connections & credentials'],
            ['label' => 'Recover', 'url' => RebuildReadiness::getUrl(), 'desc' => 'Rebuild & recovery readiness', 'provisional' => true],
        ];
    }
}
