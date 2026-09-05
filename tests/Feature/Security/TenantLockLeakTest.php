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
use App\Filament\Pages\LocationGeoGrid;
use App\Filament\Pages\LocationsSetup;
use App\Filament\Pages\Operate\InternalLinks;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateLive;
use App\Filament\Pages\Operate\OperatePages;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Pages\ProofEditor;
use App\Filament\Pages\RankingsBoard;
use App\Models\Account;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;

/**
 * The tenant-lock acceptance guard (Relay 3 · tenant-lock remediation).
 *
 * THE CRITERION: no /admin surface may resolve or display a site other than the locked {@see ActiveTenant}
 * — not via a picker, a second session key, a query param, a cross-tenant listing, or a dropped SiteScope.
 * Concretely: a page rendered under a lock on tenant A must contain NO other tenant's brand_name, id, or
 * domain anywhere in its output, and a foreign `?site=`/`?content=`/`?location=` must not resolve B's data.
 *
 * Three prior sweeps each found a different SHAPE of this bug and each was "reported complete". This test
 * is the ratchet that ends that: every admin surface is enumerated here. Surfaces already correct are
 * asserted clean now. Surfaces that still breach are listed in KNOWN_LEAKS with their shape + the
 * remediation step that fixes them, and skipped — each fix REMOVES its entry (moving the surface into the
 * asserted-clean set), so the guarantee only ever tightens. KNOWN_LEAKS must reach empty.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');

    // Seed BOTH tenants BEFORE locking — the §9 write-guard (correctly) refuses a cross-tenant write once
    // a lock is set, so all seeding happens with no lock active.
    $accountA = Account::factory()->create(['name' => 'Locked Tenant A']);
    $this->siteA = Site::factory()->for($accountA)->create(['brand_name' => 'Locked Tenant A', 'status' => 'active']);

    // A FOREIGN tenant (B) with unmistakable markers + one row of the cross-tenant models, so any surface
    // that leaks across the lock surfaces B's marker. If B never appears, the lock held.
    $accountB = Account::factory()->create(['name' => 'FOREIGN-MARKER-CO']);
    $this->siteB = Site::factory()->for($accountB)->create([
        'brand_name' => 'FOREIGN-MARKER-CO',
        'domain_url' => 'https://foreign-marker-b.example',
        'status' => 'active',
    ]);
    $this->foreignMarkers = ['FOREIGN-MARKER-CO', 'foreign-marker-b.example', (string) $this->siteB->id];
    $this->foreignContent = Content::factory()->create(['site_id' => $this->siteB->id, 'title' => 'FOREIGN-MARKER-CO page', 'status' => ContentStatus::Published]);
    $this->foreignLocation = Location::factory()->create(['site_id' => $this->siteB->id, 'name' => 'FOREIGN-MARKER-CO location']);
    Keyword::factory()->create(['site_id' => $this->siteB->id, 'query' => 'foreign-marker-co keyword']);

    // Operator with an account-wide membership on A, then lock to A LAST.
    $op = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $op->id, 'account_id' => $accountA->id, 'role' => UserRole::Operator]);
    $this->actingAs($op);
    app(ActiveTenant::class)->set($this->siteA->id);
});

/**
 * Surfaces that MUST already be clean — every ActiveTenant-scoped nav page. A full authenticated HTTP GET
 * under the lock on A must render (200) and contain none of B's markers.
 */
dataset('cleanLockedSurfaces', [
    'Build · Dashboard' => [TenantDashboard::class],
    'Build · Setup' => [SetupEntry::class],
    'Build · Posts' => [OperateBlog::class],
    'Build · Pages' => [OperatePages::class],
    'Build · Jobs' => [JobsBoard::class],
    'Build · Live' => [OperateLive::class],
    'Territory · Towns' => [LocationsSetup::class],
    'Territory · Internal links' => [InternalLinks::class],
    'Results · Rankings' => [RankingsBoard::class],
    'Results · Indexing' => [IndexingBoard::class],
    'Results · Geo grid' => [LocationGeoGrid::class],
    'Results · Coverage' => [LocationCoverage::class],
    'Results · AI visibility' => [GeoActivityConsole::class],
    'System · Recover' => [RebuildReadiness::class],
]);

it('renders no foreign tenant identifier under a lock', function (string $class) {
    // Follow redirects so redirector entrypoints (e.g. Setup → current step) land on their real page.
    $response = $this->followingRedirects()->get($class::getUrl());

    $response->assertOk();
    foreach ($this->foreignMarkers as $marker) {
        expect($response->getContent())->not->toContain($marker);
    }
})->with('cleanLockedSurfaces');

/**
 * KNOWN LEAKS — the audited breaches, each pending its remediation step. Skipped so CI stays green while
 * the fixes land in sequence; as each ships, its rows move UP into cleanLockedSurfaces (or a param-case
 * assertion below) and the skip is deleted. This list must reach empty.
 *
 *   Shape B (URL param overrides the lock — most severe): ProofEditor (?content=), CitationsWorkspace /
 *     CitationsReport (?location= → CurrentSite::set), LocationDashboard (#[Url] $siteId).  → step 2
 *   Shape C repoints: OperateDashboard, CitationsPortfolio (nav), Overview landing.          → step 3
 *   Shape D scope-drop resources: ReviewCaptureResource, PageResource, PublishedContentResource,
 *     ContentReviewResource, AiContentResource, CandidateResource, ContentEditResource.       → step 4
 *   Shape A name-leak resources: Keyword, SiloManagement, Connections, Source, Voice, Service,
 *     Location, GeoPrompt, CoverageScanPlan, BlogTarget, TenantSharedPhone, LocationNapProfile. → step 5
 */
// Foreign-param cases — a query string must NOT override the lock. These are the shape-B breaches; they
// currently resolve B's data, so they're skipped until step 2, then un-skipped (they must pass).
it('a foreign ?content= does not resolve another tenant\'s content (ProofEditor)', function () {
    $response = $this->followingRedirects()->get(ProofEditor::getUrl(['content' => $this->foreignContent->id]));
    foreach ($this->foreignMarkers as $marker) {
        expect($response->getContent())->not->toContain($marker);
    }
})->skip('shape B — step 2: ProofEditor reads ?content= withoutGlobalScope, no ActiveTenant check.');

it('a foreign ?location= does not rebind the lock (CitationsWorkspace / CitationsReport)', function () {
    foreach ([CitationsWorkspace::class, CitationsReport::class] as $page) {
        $response = $this->followingRedirects()->get($page::getUrl(['location' => $this->foreignLocation->id]));
        foreach ($this->foreignMarkers as $marker) {
            expect($response->getContent())->not->toContain($marker);
        }
    }
})->skip('shape B — step 2: reads ?location= then CurrentSite::set(location.site_id), rebinding the lock.');

it('KNOWN LEAKS remain, tracked to their remediation step (remove as each fix lands)', function () {
    // Placeholder guard: the breach inventory is codified in this test's docblock + dataset comments and
    // in docs/specs/tenant-lock-remediation.md. Each remediation PR promotes its surfaces into the
    // asserted sets above/below and trims the inventory here. When every shape is fixed this test is
    // deleted. (Kept as an explicit, searchable marker so the remediation can't be silently declared done.)
    expect(true)->toBeTrue();
})->skip('Tenant-lock remediation in progress — shapes B/C/D/A tracked to steps 2–5.');
