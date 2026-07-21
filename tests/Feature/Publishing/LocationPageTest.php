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
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockContentAssembler;
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

    // Two materialized town pages under this location → the hub links down to them.
    foreach (['Norristown' => 'norristown', 'Audubon' => 'audubon'] as $title => $slug) {
        Content::factory()->create([
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
        // areas-served grid: REAL internal links to the town pages (not just coverage prose).
        ->toContain('lp-areas')
        ->toContain('<a href="/norristown">Norristown</a>')
        ->toContain('<a href="/audubon">Audubon</a>');
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
