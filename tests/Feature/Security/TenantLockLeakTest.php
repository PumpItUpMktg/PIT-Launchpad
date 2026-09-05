<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsReport;
use App\Filament\Pages\Citations\CitationsWorkspace;
use App\Filament\Pages\Gathering\SetupEntry;
use App\Filament\Pages\GeoActivityConsole;
use App\Filament\Pages\IndexingBoard;
use App\Filament\Pages\JobsBoard;
use App\Filament\Pages\LocationCoverage;
use App\Filament\Pages\LocationDashboard;
use App\Filament\Pages\LocationGeoGrid;
use App\Filament\Pages\LocationsSetup;
use App\Filament\Pages\MarketsBoard;
use App\Filament\Pages\Operate\InternalLinks;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateLive;
use App\Filament\Pages\Operate\OperatePages;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Pages\ProofEditor;
use App\Filament\Pages\RankingsBoard;
use App\Filament\Resources\AiContentResource;
use App\Filament\Resources\CandidateResource;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\ContentEditResource;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\KeywordResource;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PublishedContentResource;
use App\Filament\Resources\ReviewCaptureResource;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\SiloManagementResource;
use App\Filament\Resources\SourceResource;
use App\Filament\Resources\VoiceProfileResource;
use App\Models\Account;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Review;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;

/**
 * The tenant-lock acceptance guard (Relay 3 · tenant-lock remediation).
 *
 * THE CRITERION: no /admin surface may resolve or display a site other than the locked {@see ActiveTenant}
 * — not via a picker, a second session key, a query param, a cross-tenant listing, or a dropped SiteScope.
 * A page rendered under a lock on tenant A must contain NO other tenant's brand_name, id, or domain
 * anywhere in its output, and a foreign `?content=`/`?location=` must not resolve that tenant's data.
 *
 * Its first job is to FAIL on today's base — three prior sweeps were "reported complete" while never
 * reaching the path. So every URL-reachable admin surface is ASSERTED here (not skipped), and the foreign
 * tenant B is seeded with a row of every model each surface renders, so a leak actually surfaces B's
 * marker. Surfaces that currently breach go RED now; each remediation step turns its surfaces green.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');

    // Seed BOTH tenants BEFORE locking — the §9 write-guard refuses a cross-tenant write once a lock is set.
    $accountA = Account::factory()->create(['name' => 'Locked Tenant A']);
    $this->siteA = Site::factory()->for($accountA)->create(['brand_name' => 'Locked Tenant A', 'status' => 'active']);

    // Foreign tenant B — unmistakable markers + a visible row of every model the surfaces render, so any
    // cross-tenant leak actually shows B. (forSite/portfolio surfaces skip a site with no rows, so "exists"
    // is not enough — B must be VISIBLE on each surface.)
    $accountB = Account::factory()->create(['name' => 'FOREIGN-MARKER-CO']);
    $this->siteB = Site::factory()->for($accountB)->create([
        'brand_name' => 'FOREIGN-MARKER-CO',
        'domain_url' => 'https://foreign-marker-b.example',
        'status' => 'active',
    ]);
    $this->markers = ['FOREIGN-MARKER-CO', 'foreign-marker-b.example', (string) $this->siteB->id];

    $b = $this->siteB->id;
    $this->foreignLocation = Location::factory()->create(['site_id' => $b, 'name' => 'FOREIGN-MARKER-CO location']);
    $this->foreignContent = Content::factory()->create(['site_id' => $b, 'title' => 'FOREIGN-MARKER-CO published', 'status' => ContentStatus::Published]);
    Content::factory()->create(['site_id' => $b, 'title' => 'FOREIGN-MARKER-CO review', 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $b, 'title' => 'FOREIGN-MARKER-CO candidate', 'status' => ContentStatus::Candidate]);
    Review::factory()->create(['site_id' => $b, 'customer_name' => 'FOREIGN-MARKER-CO reviewer']);
    Keyword::factory()->create(['site_id' => $b, 'query' => 'foreign-marker-co keyword']);
    Silo::factory()->create(['site_id' => $b, 'name' => 'FOREIGN-MARKER-CO silo']);
    Service::factory()->create(['site_id' => $b, 'name' => 'FOREIGN-MARKER-CO service']);
    ContentEdit::create(['site_id' => $b, 'content_id' => $this->foreignContent->id, 'field' => 'body', 'reason' => 'off_base', 'original' => 'x', 'edited' => 'y']);

    // A real Launchpad operator manages MANY tenants — membership in BOTH accounts, so both are visible
    // through VisibleSiteScope. The lock ({@see ActiveTenant}) must still constrain every surface to A.
    // (Membership in A alone would hide B behind VisibleSiteScope and the test would never reach the breach
    // — the exact "green while never reaching the path" trap the prior sweeps fell into.)
    $op = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $op->id, 'account_id' => $accountA->id, 'role' => UserRole::Operator]);
    Membership::create(['user_id' => $op->id, 'account_id' => $accountB->id, 'role' => UserRole::Operator]);
    $this->actingAs($op);
    app(ActiveTenant::class)->set($this->siteA->id);
});

/** Assert a locked surface renders (200 after redirects) and contains none of tenant B's markers. */
function assertNoForeignLeak($test, string $url): void
{
    $response = $test->followingRedirects()->get($url);
    $response->assertOk();
    foreach ($test->markers as $marker) {
        expect($response->getContent())->not->toContain($marker);
    }
}

/**
 * Assert a foreign URL param is DENIED: the surface must not resolve or display tenant B's data. A 404
 * (the record isn't found within the locked tenant) is a correct denial; a 200 is acceptable only if it
 * carries none of B's markers. A 5xx is never acceptable.
 */
function assertForeignParamDenied($test, string $url): void
{
    $response = $test->followingRedirects()->get($url);
    expect($response->getStatusCode())->toBeLessThan(500);
    foreach ($test->markers as $marker) {
        expect($response->getContent())->not->toContain($marker);
    }
}

// EVERY URL-reachable admin surface that must be tenant-locked. Nav pages + portfolio/overview surfaces +
// every resource index (all URL-reachable via discoverResources — off-nav is not a mitigation).
dataset('lockedSurfaces', [
    // Nav pages expected correct today.
    'Build · Dashboard' => [fn () => TenantDashboard::getUrl()],
    'Build · Setup' => [fn () => SetupEntry::getUrl()],
    'Build · Posts' => [fn () => OperateBlog::getUrl()],
    'Build · Pages' => [fn () => OperatePages::getUrl()],
    'Build · Jobs' => [fn () => JobsBoard::getUrl()],
    'Build · Live' => [fn () => OperateLive::getUrl()],
    'Territory · Markets' => [fn () => MarketsBoard::getUrl()],
    'Territory · Towns' => [fn () => LocationsSetup::getUrl()],
    'Territory · Internal links' => [fn () => InternalLinks::getUrl()],
    'Results · Rankings' => [fn () => RankingsBoard::getUrl()],
    'Results · Indexing' => [fn () => IndexingBoard::getUrl()],
    'Results · Geo grid' => [fn () => LocationGeoGrid::getUrl()],
    'Results · Coverage' => [fn () => LocationCoverage::getUrl()],
    'Results · AI visibility' => [fn () => GeoActivityConsole::getUrl()],
    'System · Recover' => [fn () => RebuildReadiness::getUrl()],
    // Every resource index (URL-reachable regardless of nav).
    'Resource · Reviews (capture)' => [fn () => ReviewCaptureResource::getUrl('index')],
    'Resource · Pages' => [fn () => PageResource::getUrl('index')],
    'Resource · Published content' => [fn () => PublishedContentResource::getUrl('index')],
    'Resource · Content review' => [fn () => ContentReviewResource::getUrl('index')],
    'Resource · AI content' => [fn () => AiContentResource::getUrl('index')],
    'Resource · Candidates' => [fn () => CandidateResource::getUrl('index')],
    'Resource · Content edits' => [fn () => ContentEditResource::getUrl('index')],
    'Resource · Keywords' => [fn () => KeywordResource::getUrl('index')],
    'Resource · Silos' => [fn () => SiloManagementResource::getUrl('index')],
    'Resource · Connections' => [fn () => ConnectionsResource::getUrl('index')],
    'Resource · Feeds' => [fn () => SourceResource::getUrl('index')],
    'Resource · Voice' => [fn () => VoiceProfileResource::getUrl('index')],
    'Resource · Services' => [fn () => ServiceResource::getUrl('index')],
    'Resource · Locations' => [fn () => LocationResource::getUrl('index')],
]);

it('renders no foreign tenant identifier under a lock', function (Closure $url) {
    assertNoForeignLeak($this, $url());
})->with('lockedSurfaces');

// Foreign-param cases — a query string must NOT override the lock (shape B, the most severe).
it('a foreign ?content= does not resolve another tenant\'s content (ProofEditor)', function () {
    assertForeignParamDenied($this, ProofEditor::getUrl(['content' => $this->foreignContent->id]));
});

it('a foreign ?location= does not rebind the lock (CitationsWorkspace)', function () {
    assertForeignParamDenied($this, CitationsWorkspace::getUrl(['location' => $this->foreignLocation->id]));
});

it('a foreign ?location= does not rebind the lock (CitationsReport)', function () {
    assertForeignParamDenied($this, CitationsReport::getUrl(['location' => $this->foreignLocation->id]));
});

it('a foreign ?siteId= does not override the lock (LocationDashboard)', function () {
    assertForeignParamDenied($this, LocationDashboard::getUrl([
        'siteId' => $this->siteB->id,
        'locationId' => $this->foreignLocation->id,
    ]));
});
