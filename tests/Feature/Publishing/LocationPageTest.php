<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Local\Proof\LocalJob;
use App\Local\Proof\LocalJobProvider;
use App\Local\Proof\LocalReview;
use App\Local\Proof\LocalReviewProvider;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\Blocks\ServiceAreaMap;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use App\Publishing\Schema\LocationSchemaBuilder;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Collection;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function locRelaySite(): Site
{
    return Site::factory()->create([
        'domain_url' => 'https://drybasements.example',
        'brand_name' => 'Dry Basements Co',
    ]);
}

function locRelayLocation(Site $site, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => 'Trooper office',
        'phone' => '(610) 555-0142',
        'is_storefront' => false,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Trooper', 'short_name' => 'Trooper'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA'],
            ['types' => ['postal_code'], 'long_name' => '19403', 'short_name' => '19403'],
        ],
        'served_towns' => [
            ['name' => 'Norristown', 'state' => 'PA', 'lat' => 40.1215, 'lng' => -75.3399, 'geocoded' => true],
            ['name' => 'Audubon', 'state' => 'PA', 'lat' => 40.1259, 'lng' => -75.4327, 'geocoded' => true],
            ['name' => 'Eagleville', 'state' => 'PA', 'lat' => null, 'lng' => null, 'geocoded' => false],
        ],
        'market_notes' => 'Lots of 1950s stone foundations near the Schuylkill; spring water tables run high.',
        'grounding_cache' => [
            'facts' => ['Annual precipitation averages about 48 inches.'],
            'sources' => ['open-meteo climate normals'],
            'fetched_at' => now()->toIso8601String(), // fresh — grounding never refetches in these tests
        ],
    ], $overrides));
}

function locRelayPage(Site $site, Location $location, array $overrides = []): Content
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', 'location')->orderByDesc('version')->firstOrFail();

    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'location_id' => $location->id,
        'title' => 'Trooper, PA',
        'slug' => 'trooper-pa',
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => [
            'loc_intro' => 'We have waterproofed basements around Trooper for years — stone foundations, modern slabs, and everything the spring water table throws at them.',
            'faq' => [
                ['question' => 'Do you serve Norristown?', 'answer' => 'Yes — Norristown is part of our core service area.'],
            ],
        ],
    ], $overrides));
}

it('composes the location page: formula H1, live-page link rule, coverage from served towns, the location phone', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);

    // One service with a LIVE page (pushed — wp_post_id set) → links; one without → text only.
    $sump = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation', 'description' => 'Reliable sump systems.']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drains', 'description' => 'Interior drainage done right.']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation',
        'primary_service_id' => $sump->id, 'wp_post_id' => 77,
    ]);

    $page = locRelayPage($site, $location);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()
        // The deterministic H1 formula (no drafted headline in the payload).
        ->toContain('Basement waterproofing in Trooper, PA')
        // The link rule: the live service page links; the page-less service renders as text.
        ->toContain('href="https://drybasements.example/sump-pump-installation"')
        ->toContain('French Drains')
        // Coverage prose derives honestly from the served towns (readable list, not a keyword dump).
        ->toContain('Norristown, Audubon, and Eagleville')
        // The CTA/hero carry the LOCATION's own phone.
        ->toContain('tel:6105550142')
        ->toContain('(610) 555-0142')
        ->toContain('Do you serve Norristown?')
        // Local conditions: the cached grounding facts now render as a section, not drafter-only.
        ->toContain('About Trooper')
        ->toContain('Annual precipitation averages about 48 inches.');
    expect($markup)->not->toContain('href="https://drybasements.example/french');
});

it('drops the Local conditions section when the location has no grounding facts', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, ['grounding_cache' => null]); // never grounded
    $sump = Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation',
        'primary_service_id' => $sump->id, 'wp_post_id' => 77,
    ]);

    $page = locRelayPage($site, $location);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    // Data-gated: no facts → no "About {City}" conditions block (never a fabricated local stat).
    expect($markup)->toBeString()->not->toContain('About Trooper');
});

it('a drafted hero headline overrides the formula', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);

    $page = locRelayPage($site, $location);
    $page->forceFill(['slot_payload' => array_merge($page->slot_payload, [
        'hero_headline' => 'Trooper’s dry-basement specialists',
    ])])->save();

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('Trooper’s dry-basement specialists')
        ->not->toContain('Basement waterproofing in Trooper, PA');
});

it('reviews and jobs sections are strictly gated — omitted with the null providers in BOTH contexts', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    $page = locRelayPage($site, $location);

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);

    // No headers over nothing, no placeholders — nothing an operator does today can fill these.
    foreach ([$publish, $preview] as $markup) {
        expect($markup)->not->toContain('lp-testimonials')
            ->not->toContain('lp-jobs')
            ->not->toContain('What neighbors say')
            ->not->toContain('Recent jobs near');
    }
});

it('provider-fed reviews and jobs render the moment real providers bind', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    $page = locRelayPage($site, $location);

    app()->instance(LocalReviewProvider::class, new class implements LocalReviewProvider
    {
        public function for(Location $location): array
        {
            return [new LocalReview('Maria', 5, 'They dried out our stone basement for good.', 'Norristown')];
        }
    });
    app()->instance(LocalJobProvider::class, new class implements LocalJobProvider
    {
        public function for(Location $location): array
        {
            return [new LocalJob('Sump pump install', 'Full perimeter drain + sump in a 1950s foundation.', [], 'Audubon', null, 'March 2026')];
        }
    });

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('They dried out our stone basement for good.')
        ->toContain('What neighbors say')
        ->toContain('Sump pump install')
        ->toContain('Audubon · March 2026')
        ->toContain('lp-jobs');
});

it('builds the LocalBusiness node — areaServed from served towns, storefront-gated address/geo/hasMap, no review props', function () {
    $site = locRelaySite();
    $storefront = locRelayLocation($site, [
        'is_storefront' => true,
        'address' => '100 Park Ave, Trooper, PA 19403',
        'gbp_url' => 'https://maps.google.com/?cid=123',
        'lat' => 40.1502, 'lng' => -75.4013,
    ]);
    $page = locRelayPage($site, $storefront);

    $node = app(LocationSchemaBuilder::class)->buildForLocation(
        $page->fresh(), $storefront, $site, 'https://drybasements.example/', 'https://drybasements.example/trooper-pa',
    );

    expect($node['@type'])->toBe('LocalBusiness')
        ->and($node['@id'])->toBe('https://drybasements.example/#location-trooper-pa')
        ->and($node['telephone'])->toBe('(610) 555-0142')
        ->and($node['url'])->toBe('https://drybasements.example/trooper-pa')
        ->and($node['geo']['latitude'])->toEqual(40.1502)
        ->and($node['hasMap'])->toBe('https://maps.google.com/?cid=123')
        ->and(collect($node['areaServed'])->pluck('name')->all())->toBe(['Trooper', 'Norristown', 'Audubon', 'Eagleville'])
        ->and($node['areaServed'][1]['containedInPlace']['name'])->toBe('PA');
    // NO review properties until a real review source exists (Google guideline).
    expect($node)->not->toHaveKeys(['review', 'aggregateRating']);

    // A service-area business omits the street address entirely — geo/hasMap gate with it.
    $sab = locRelayLocation($site, ['lat' => 40.15, 'lng' => -75.40, 'served_towns' => []]);
    $sabNode = app(LocationSchemaBuilder::class)->buildForLocation(
        locRelayPage($site, $sab, ['slug' => 'trooper-2', 'title' => 'Trooper 2']),
        $sab, $site, 'https://drybasements.example/', null,
    );
    expect($sabNode)->not->toHaveKeys(['address', 'geo', 'hasMap'])
        ->and(collect($sabNode['areaServed'])->pluck('name')->all())->toBe(['Trooper']);
});

it('generate-location guards a location with no city and no served towns, naming the fix', function () {
    $site = locRelaySite();
    $bare = Location::factory()->create([
        'site_id' => $site->id, 'name' => '', 'address_components' => null, 'served_towns' => null,
    ]);

    test()->artisan('launchpad:generate-location', ['location' => $bare->id])
        ->expectsOutputToContain('no city and no served towns')
        ->assertFailed();
});

it('generate-location creates the pinned page once and drives the drafting path (idempotent)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);
    (new WireframeKitSeeder)->run();

    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient(Draft::json([
        'slots' => [
            'hero_headline' => 'Basement waterproofing in Trooper, PA',
            'hero_subhead' => 'Fast, honest help for wet basements across the Trooper area.',
            'loc_intro' => 'From the stone foundations near the Schuylkill to newer slabs in Audubon, we keep Trooper-area basements dry through the spring water-table surge — honest assessments, clean installs.',
            'loc_services_intro' => 'Here is what we do across the Trooper area.',
            'loc_coverage' => 'We cover Norristown, Audubon, and Eagleville — the towns immediately around our Trooper base.',
            'faq' => [
                ['question' => 'Do you serve Norristown?', 'answer' => 'Yes, Norristown is core coverage.'],
                ['question' => 'How fast can you assess?', 'answer' => 'Usually within a few days.'],
                ['question' => 'Do you handle stone foundations?', 'answer' => 'Yes — they are common here.'],
            ],
        ],
    ])))));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);

    test()->artisan('launchpad:generate-location', ['location' => $location->id])->assertSuccessful();

    $pages = Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('page_type', PageType::Location->value)
        ->get();
    expect($pages)->toHaveCount(1)
        ->and($pages->first()->title)->toBe('Trooper, PA')
        ->and($pages->first()->location_id)->toBe($location->id)
        ->and($pages->first()->status)->toBe(ContentStatus::NeedsReview)
        ->and($pages->first()->slug)->not->toBeEmpty();

    // Re-run: reuses the SAME pinned row — never a duplicate page.
    test()->artisan('launchpad:generate-location', ['location' => $location->id])->assertSuccessful();
    expect(Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('page_type', PageType::Location->value)
        ->count())->toBe(1);
});

it('the drafter prompt carries the location subject — market notes verbatim, served towns, grounded facts', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);
    $page = locRelayPage($site, $location, ['slot_payload' => []]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page->fresh());

    expect($grounding->location)->toHaveKeys(['city', 'state', 'phone', 'served_towns', 'market_notes', 'local_facts'])
        ->and($grounding->location['city'])->toBe('Trooper')
        ->and($grounding->location['served_towns'])->toContain('Norristown');

    $prompt = (new PageDrafter(new DraftCall(new FakeClaudeClient(''))))->preview($grounding)['prompt'];

    expect($prompt)->toContain('LOCATION —')
        ->toContain('Lots of 1950s stone foundations near the Schuylkill')
        ->toContain('Norristown')
        ->toContain('Annual precipitation averages about 48 inches.')
        ->toContain('NEVER invent local details');
});

it('a location page WITHOUT a pin keeps the null fallback and the drafter gets no location block', function () {
    $site = locRelaySite();
    $unpinned = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'slug' => 'trenton', 'title' => 'Trenton',
    ]);

    expect(app(BlockContentAssembler::class)->compose($unpinned->fresh(), [], []))->toBeNull();
});

it('the location hub renders its NAP (address + hours + phone) and LINKS to its town pages', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, [
        'is_storefront' => true,
        'address' => '10 Trooper Rd, Trooper, PA 19403',
        'email' => 'trooper@drybasements.example',
        'hours' => ['mon' => ['open' => '09:00', 'close' => '18:00'], 'tue' => ['open' => '09:00', 'close' => '18:00']],
    ]);
    $page = locRelayPage($site, $location);

    // Two PUBLISHED town pages under this location → the hub links down to them (only live towns link).
    foreach (['Norristown' => 'norristown', 'Audubon' => 'audubon'] as $title => $slug) {
        Content::factory()->published()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
            'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null,
            'title' => $title, 'slug' => $slug,
        ]);
    }

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        // NAP: storefront address + hours + the location's own phone.
        ->toContain('10 Trooper Rd')
        ->toContain('(610) 555-0142')
        ->toContain('9am')
        // areas-served list: REAL internal links to the town pages (not just coverage prose).
        ->toContain('lp-areas')
        ->toContain('<a href="/norristown">Norristown</a>')
        ->toContain('<a href="/audubon">Audubon</a>');
});

it('the areas list is a de-duped, published-only, comma-flowing line ordered largest-first, no ", ST", no size labels', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, ['is_storefront' => true, 'address' => '10 Trooper Rd, Trooper, PA 19403']);
    $page = locRelayPage($site, $location);

    // Census size tiers order the list largest-first (Norristown large → Audubon small).
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '4209111111', 'name' => 'Norristown', 'type' => 'county_subdivision', 'state' => 'PA', 'size_tier' => 'large', 'population' => 35000, 'source' => 'county']);
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '4209122222', 'name' => 'Audubon', 'type' => 'county_subdivision', 'state' => 'PA', 'size_tier' => 'small', 'population' => 3000, 'source' => 'county']);

    // Titles carry ", PA" (dropped in the display); a DUPLICATE Norristown row + a DRAFT row must both
    // be collapsed away (one live link per town).
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Norristown, PA', 'slug' => 'norristown']);
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Norristown, PA', 'slug' => 'norristown-dup']); // duplicate
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Audubon, PA', 'slug' => 'audubon-draft']); // draft → excluded
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Audubon, PA', 'slug' => 'audubon']);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('lp-areas--towns')
        ->toContain('lp-areas-townlist')
        ->not->toContain('lp-areas-bandlabel')         // no size labels
        ->not->toContain('Larger cities')
        ->toContain('>Norristown</a>')                 // ", PA" dropped in display
        ->toContain('>Audubon</a>')
        ->not->toContain('Norristown, PA</a>');        // state suffix not shown

    // De-duped: exactly ONE Norristown link (the duplicate + draft rows collapsed).
    expect(substr_count($markup, '>Norristown</a>'))->toBe(1)
        // Largest-first: Norristown (large) precedes Audubon (small).
        ->and(strpos($markup, '>Norristown</a>'))->toBeLessThan(strpos($markup, '>Audubon</a>'));
});

it('forLocation builds a scoped areas map — its served towns as LINKED points + a location pin', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, ['lat' => 40.12, 'lng' => -75.34, 'county_geoids' => ['42091']]);

    // A geocoded coverage town + its published town page under this location → a clickable point.
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '4209153000', 'name' => 'Norristown', 'type' => 'county_subdivision', 'state' => 'PA', 'lat' => 40.12, 'lng' => -75.34, 'size_tier' => 'large', 'population' => 35000, 'source' => 'county']);
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Norristown, PA', 'slug' => 'norristown']);
    // A geocoded town with NO page → not a point (every pin must link).
    CoverageArea::withoutGlobalScopes()->create(['site_id' => $site->id, 'geo_id' => '4209199999', 'name' => 'Audubon', 'type' => 'county_subdivision', 'state' => 'PA', 'lat' => 40.13, 'lng' => -75.44, 'size_tier' => 'small', 'population' => 3000, 'source' => 'county']);

    $map = app(ServiceAreaMap::class)->forLocation($location->fresh());

    expect($map)->not->toBeNull()
        ->and($map['cities'])->toHaveCount(1)                       // only the town with a page
        ->and($map['cities'][0]['name'])->toBe('Norristown')        // ", PA" stripped
        ->and($map['cities'][0]['url'])->toBe('/norristown')        // links its town page
        ->and($map['pin']['lat'])->toBe(40.12);                     // the location pin
});

it('the location hub renders the interactive map mount above the town list when a map is available', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, ['is_storefront' => true, 'address' => '10 Trooper Rd, Trooper, PA 19403']);
    $page = locRelayPage($site, $location);
    Content::factory()->published()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_location_id' => $location->id, 'location_id' => null, 'primary_service_id' => null, 'title' => 'Norristown, PA', 'slug' => 'norristown']);

    // mapAvailable: true → the section carries the Leaflet mount (fed by the meta-blob) AND the list.
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], mapAvailable: true);

    expect($markup)
        ->toContain('lp-areas--map')
        ->toContain('lp-areas-map')                    // the Leaflet mount
        ->toContain('<a href="/norristown">Norristown</a>'); // the crawlable fallback list stays
});

it('the location hub drops the address for a non-storefront (mobile base stays private) but keeps hours', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, [
        'is_storefront' => false,
        'address' => '10 Private Garage Rd, Trooper, PA',
        'hours' => ['mon' => ['open' => '08:00', 'close' => '17:00']],
    ]);
    $page = locRelayPage($site, $location);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->not->toContain('Private Garage')   // mobile base address never ships
        ->toContain('8am');                          // hours still render
});

it('emits a Find-us map for a location with GBP coordinates (lp_map shortcode + location_map slot)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site, ['lat' => 40.1345, 'lng' => -75.3401]);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);
    $page = locRelayPage($site, $location);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    // The coords ride the blob as the location_map slot, and the block body carries the shortcode the
    // plugin renders into a keyless Google embed (a raw iframe would be kses-stripped).
    expect($blob['slot_payload']['location_map'])->toBe(['lat' => 40.1345, 'lng' => -75.3401])
        ->and($blob['post_content'])->toContain('[lp_map key="location_map"]');
});

it('renders NO map section when the location has no coordinates (never an empty embed)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site); // no lat/lng
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);
    $page = locRelayPage($site, $location);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    expect($blob['slot_payload'])->not->toHaveKey('location_map')
        ->and($blob['post_content'])->not->toContain('[lp_map');
});

it('lists the town\'s recent posts as a local blog feed on the location page (§B)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);

    // Two published posts tagged with the location's own town (Trooper) — the feed lists them, newest first.
    $newer = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Spring water tables in Trooper', 'slug' => 'spring-water-tables-trooper',
        'published_at' => now()->subDays(2),
    ]);
    $older = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Stone foundation repair tips', 'slug' => 'stone-foundation-repair-tips',
        'published_at' => now()->subDays(5),
    ]);
    foreach ([$newer, $older] as $p) {
        ContentTown::query()->create([
            'content_id' => $p->id, 'site_id' => $site->id, 'town' => 'trooper', 'town_display' => 'Trooper',
        ]);
    }

    $page = locRelayPage($site, $location);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()
        ->toContain('Latest from Trooper')
        ->toContain('Spring water tables in Trooper')
        ->toContain('href="/spring-water-tables-trooper"')
        ->toContain('Stone foundation repair tips');

    // Newest-first ordering.
    expect(strpos($markup, 'Spring water tables in Trooper'))
        ->toBeLessThan(strpos($markup, 'Stone foundation repair tips'));
});

it('drops the local blog feed when no posts are tagged with the town (§B)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);

    // A published post exists but is tagged with a DIFFERENT town → this location's feed stays empty.
    $other = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Elsewhere news', 'slug' => 'elsewhere-news', 'published_at' => now(),
    ]);
    ContentTown::query()->create([
        'content_id' => $other->id, 'site_id' => $site->id, 'town' => 'norristown', 'town_display' => 'Norristown',
    ]);

    $page = locRelayPage($site, $location);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()
        ->not->toContain('Latest from Trooper')
        ->not->toContain('Elsewhere news');
});

it('a town page breadcrumb is 3-level: Home → GBP location hub → town (§ report fix 4)', function () {
    $site = locRelaySite();
    $location = locRelayLocation($site);
    // The GBP location hub page (Trooper, PA) and a town page nested under it.
    $hub = locRelayPage($site, $location);
    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => 'Norristown, PA', 'slug' => 'trooper-pa/norristown',
        'parent_content_id' => $hub->id, 'wireframe_kit_id' => $hub->wireframe_kit_id,
    ]);

    $crumbs = app(MetaBlobAssembler::class)->assemble($town->fresh(), new Collection)['seo']['breadcrumbs'];

    expect($crumbs)->toHaveCount(3)
        ->and($crumbs[0]['name'])->toBe('Home')
        ->and($crumbs[1]['name'])->toBe('Trooper, PA')
        ->and($crumbs[1]['url'])->toContain('/trooper-pa')
        ->and($crumbs[2]['name'])->toContain('Norristown')
        ->and($crumbs[2]['url'])->toBe('');
});
