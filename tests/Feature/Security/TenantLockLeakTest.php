<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
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

// EVERY URL-reachable admin surface that must be tenant-locked. Nav pages + portfolio/overview surfaces +
// every resource index (all URL-reachable via discoverResources — off-nav is not a mitigation).
dataset('lockedSurfaces', [
    // Nav pages expected correct today.
    'Build · Dashboard' => [fn () => \App\Filament\Pages\Operate\TenantDashboard::getUrl()],
    'Build · Setup' => [fn () => \App\Filament\Pages\Gathering\SetupEntry::getUrl()],
    'Build · Posts' => [fn () => \App\Filament\Pages\Operate\OperateBlog::getUrl()],
    'Build · Pages' => [fn () => \App\Filament\Pages\Operate\OperatePages::getUrl()],
    'Build · Jobs' => [fn () => \App\Filament\Pages\JobsBoard::getUrl()],
    'Build · Live' => [fn () => \App\Filament\Pages\Operate\OperateLive::getUrl()],
    'Territory · Markets' => [fn () => \App\Filament\Pages\MarketsBoard::getUrl()],
    'Territory · Towns' => [fn () => \App\Filament\Pages\LocationsSetup::getUrl()],
    'Territory · Internal links' => [fn () => \App\Filament\Pages\Operate\InternalLinks::getUrl()],
    'Results · Rankings' => [fn () => \App\Filament\Pages\RankingsBoard::getUrl()],
    'Results · Indexing' => [fn () => \App\Filament\Pages\IndexingBoard::getUrl()],
    'Results · Geo grid' => [fn () => \App\Filament\Pages\LocationGeoGrid::getUrl()],
    'Results · Coverage' => [fn () => \App\Filament\Pages\LocationCoverage::getUrl()],
    'Results · AI visibility' => [fn () => \App\Filament\Pages\GeoActivityConsole::getUrl()],
    'System · Recover' => [fn () => \App\Filament\Pages\Operate\RebuildReadiness::getUrl()],
    // Cross-tenant surfaces in tenant nav / the locked landing (expected RED until fixed).
    'Territory · Citations (portfolio)' => [fn () => \App\Filament\Pages\Citations\CitationsPortfolio::getUrl()],
    'Landing · OperateDashboard' => [fn () => \App\Filament\Pages\Operate\OperateDashboard::getUrl()],
    'Landing · Overview' => [fn () => \App\Filament\Pages\Overview::getUrl()],
    // Every resource index (URL-reachable regardless of nav).
    'Resource · Reviews (capture)' => [fn () => \App\Filament\Resources\ReviewCaptureResource::getUrl('index')],
    'Resource · Pages' => [fn () => \App\Filament\Resources\PageResource::getUrl('index')],
    'Resource · Published content' => [fn () => \App\Filament\Resources\PublishedContentResource::getUrl('index')],
    'Resource · Content review' => [fn () => \App\Filament\Resources\ContentReviewResource::getUrl('index')],
    'Resource · AI content' => [fn () => \App\Filament\Resources\AiContentResource::getUrl('index')],
    'Resource · Candidates' => [fn () => \App\Filament\Resources\CandidateResource::getUrl('index')],
    'Resource · Content edits' => [fn () => \App\Filament\Resources\ContentEditResource::getUrl('index')],
    'Resource · Keywords' => [fn () => \App\Filament\Resources\KeywordResource::getUrl('index')],
    'Resource · Silos' => [fn () => \App\Filament\Resources\SiloManagementResource::getUrl('index')],
    'Resource · Connections' => [fn () => \App\Filament\Resources\ConnectionsResource::getUrl('index')],
    'Resource · Feeds' => [fn () => \App\Filament\Resources\SourceResource::getUrl('index')],
    'Resource · Voice' => [fn () => \App\Filament\Resources\VoiceProfileResource::getUrl('index')],
    'Resource · Services' => [fn () => \App\Filament\Resources\ServiceResource::getUrl('index')],
    'Resource · Locations' => [fn () => \App\Filament\Resources\LocationResource::getUrl('index')],
]);

it('renders no foreign tenant identifier under a lock', function (Closure $url) {
    assertNoForeignLeak($this, $url());
})->with('lockedSurfaces');

// Foreign-param cases — a query string must NOT override the lock (shape B, the most severe).
it('a foreign ?content= does not resolve another tenant\'s content (ProofEditor)', function () {
    assertNoForeignLeak($this, \App\Filament\Pages\ProofEditor::getUrl(['content' => $this->foreignContent->id]));
});

it('a foreign ?location= does not rebind the lock (CitationsWorkspace / CitationsReport)', function () {
    assertNoForeignLeak($this, \App\Filament\Pages\Citations\CitationsWorkspace::getUrl(['location' => $this->foreignLocation->id]));
    assertNoForeignLeak($this, \App\Filament\Pages\Citations\CitationsReport::getUrl(['location' => $this->foreignLocation->id]));
});
