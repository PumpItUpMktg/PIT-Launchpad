<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seed a page_index_states row directly (bypassing the sync path) for the orphan report tests. */
function seedIndexRow(Site $site, ?Content $content, string $url, string $verdict): string
{
    $id = (string) Str::ulid();
    DB::table('page_index_states')->insert([
        'id' => $id,
        'site_id' => $site->id,
        'content_id' => $content?->id,
        'url' => $url,
        'url_normalized' => UrlNormalizer::url($url),
        'coverage_state' => $verdict === 'PASS' ? 'Submitted and indexed' : 'Page with redirect',
        'index_verdict' => $verdict,
        'canonical_url' => null,
        'last_inspected_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    return $id;
}

it('report-county-mismatch and report-town-assignment run read-only and state live-only', function () {
    Site::factory()->create();

    $this->artisan('launchpad:report-county-mismatch')->assertSuccessful()->expectsOutputToContain('LIVE-ONLY');
    $this->artisan('launchpad:report-town-assignment')->assertSuccessful()->expectsOutputToContain('LIVE-ONLY');
});

it('report-duplicate-locations is report-only by default and removes only with --execute', function () {
    $site = Site::factory()->create();
    $real = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '1 A St', 'county_geoids' => ['42091'], 'is_storefront' => true]);
    $stub = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '1 A St', 'county_geoids' => [], 'is_storefront' => false]);

    // Default: report-only, nothing removed.
    $this->artisan('launchpad:report-duplicate-locations')->assertSuccessful()->expectsOutputToContain('Report-only');
    expect(Location::find($stub->id))->not->toBeNull();

    // --execute removes the stub, never the real row.
    $this->artisan('launchpad:report-duplicate-locations --execute')->assertSuccessful();
    expect(Location::find($stub->id))->toBeNull()
        ->and(Location::find($real->id))->not->toBeNull();
});

it('report-orphan-index-states finds stale-URL and content-gone orphans, keeps canonical rows, prunes with --execute', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    // A home page whose canonical URL is the root; a prior sync left a stale row at /home/.
    $home = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'home', 'title' => 'Home']);
    $canonicalUrl = (string) PublicUrl::forContent($site->domain_url, $home);

    $canonicalId = seedIndexRow($site, $home, $canonicalUrl, 'PASS');                 // canonical — keep
    $staleId = seedIndexRow($site, $home, 'https://apex.example/home/', 'excluded_redirect'); // the masked bug — orphan

    // A service page whose content was soft-deleted → its row is an orphan.
    $gone = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 2, 'slug' => 'gone', 'title' => 'Gone']);
    $goneUrl = (string) PublicUrl::forContent($site->domain_url, $gone);
    $goneId = seedIndexRow($site, $gone, $goneUrl, 'PASS');
    $gone->delete();

    // A live service page correctly inspected at its canonical URL → not an orphan.
    $live = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 3, 'slug' => 'live', 'title' => 'Live']);
    $liveId = seedIndexRow($site, $live, (string) PublicUrl::forContent($site->domain_url, $live), 'PASS');

    // Report-only: names 2 orphans (1 stale-URL, 1 content-gone), classifies the excluded_redirect masked bug, prunes nothing.
    $this->artisan('launchpad:report-orphan-index-states')
        ->assertSuccessful()
        ->expectsOutputToContain('Report-only');
    expect(PageIndexState::withoutGlobalScopes()->whereIn('id', [$staleId, $goneId])->count())->toBe(2);

    // --execute prunes only the orphans; the canonical + live rows survive.
    $this->artisan('launchpad:report-orphan-index-states --execute')->assertSuccessful();
    expect(PageIndexState::withoutGlobalScopes()->find($staleId))->toBeNull()
        ->and(PageIndexState::withoutGlobalScopes()->find($goneId))->toBeNull()
        ->and(PageIndexState::withoutGlobalScopes()->find($canonicalId))->not->toBeNull()
        ->and(PageIndexState::withoutGlobalScopes()->find($liveId))->not->toBeNull();
});

it('report-title-lengths measures the page portion and the brand-suffix headroom, read-only', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);

    // Home carries the service_area; coverage states drive the region qualifier on service/hub titles.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home,
        'status' => ContentStatus::Published, 'slug' => 'home', 'title' => 'Home',
        'slot_payload' => ['service_area' => 'New Jersey & Eastern Pennsylvania'],
    ]);
    \App\Models\CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3401', 'name' => 'Newark', 'state' => 'NJ']);
    \App\Models\CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4209', 'name' => 'Reading', 'state' => 'PA']);

    // Service page: region-qualifies to a 59-char portion (≤60), but + " | Sump Pump Gurus" (18) blows past 60.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'slug' => 'basement-waterproofing',
        'meta' => ['seo' => ['title' => 'Basement Waterproofing', 'meta_description' => 'x']],
    ]);
    // Utility page: short, comfortably fits the brand suffix.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility,
        'status' => ContentStatus::Published, 'slug' => 'about',
        'meta' => ['seo' => ['title' => 'About Us', 'meta_description' => 'x']],
    ]);

    $entry = app(\App\Publishing\TitleLengthReport::class)->forSite($site->fresh());

    expect($entry['total'])->toBe(3)
        ->and($entry['brand_cost'])->toBe(18)                       // " | " + "Sump Pump Gurus"
        ->and($entry['over'])->toBe(0)                              // page portion is capped at 60 by construction
        ->and($entry['brand_over'])->toBe(1)                        // only the region-qualified service page overflows
        ->and($entry['max'])->toBe(59);

    // The service page: portion within 60, projected over once the brand is composed on.
    $service = collect($entry['pages'])->firstWhere('slug', 'basement-waterproofing');
    expect($service['title'])->toBe('Basement Waterproofing in New Jersey & Eastern Pennsylvania')
        ->and($service['len'])->toBe(59)
        ->and($service['over'])->toBeFalse()
        ->and($service['with_brand'])->toBe(77)
        ->and($service['brand_over'])->toBeTrue();

    // The command runs read-only and reports the structural finding + the headroom count.
    $this->artisan('launchpad:report-title-lengths')
        ->assertSuccessful()
        ->expectsOutputToContain('READ-ONLY');
});
