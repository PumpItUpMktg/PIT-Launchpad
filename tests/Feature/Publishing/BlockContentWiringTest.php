<?php

use App\Enums\ContentKind;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;
use Tests\Support\FakeClaudeClient;

function blockHomePage(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Home,
        'slug' => 'home',
        'title' => 'Home',
        'slot_payload' => [
            'hero_headline' => 'Stop sewer problems before they shut you down.',
            'hero_subhead' => 'Preventive maintenance for commercial buildings across Northern NJ.',
            'service_area' => 'Commercial Plumbing · Northern NJ',
        ],
    ]);
}

function blockServicePage(Site $site, string $title, string $slug, string $blurb): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'slug' => $slug,
        'title' => $title,
        'meta' => ['seo' => ['meta_description' => $blurb]],
    ]);
}

it('composes Home post_content from real inputs — cards link to real pages, phone + emergency resolved', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'offers_emergency' => true]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(973) 555-0100']);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => 'Licensed & insured'], 'is_substantiated' => true,
    ]);
    blockServicePage($site, 'Drain Cleaning', 'drain-cleaning', 'Snaking and hydro-jetting.');
    blockServicePage($site, 'Sewer Line Services', 'sewer-line-services', 'Repair and replacement.');

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose(
        $home->fresh(),
        $home->slot_payload,
        ['hero_image' => ['url' => 'https://cdn.example/hero.webp', 'alt' => 'On site']],
    );

    expect($markup)->toBeString()->not->toBeEmpty()
        // real block markup, not elementor / flat prose
        ->and($markup)->toContain('<!-- wp:group {"backgroundColor":"primary"')
        ->toContain('Stop sewer problems before they shut you down.')
        // service cards link to the REAL child pages
        ->toContain('href="https://sewergurus.com/drain-cleaning"')
        ->toContain('href="https://sewergurus.com/sewer-line-services"')
        // resolved click-to-call + emergency treatment
        ->toContain('href="tel:9735550100"')
        ->toContain('24/7')
        // substantiated proof stat (not fabricated)
        ->toContain('Licensed &amp; insured');
});

it('composes the full 9-section Home from real §1 data — credibility, differentiators, testimonials, areas', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'offers_emergency' => true]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(973) 555-0100']);

    // Credibility badge — a substantiated license.
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::License,
        'payload' => ['label' => 'NJ Master Plumber'], 'is_substantiated' => true,
    ]);
    // Testimonial — substantiated review with quote/author/role/stars.
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'Caught a collapsing line before it flooded the basement.', 'author' => 'Facilities Director', 'role' => 'Jersey City', 'stars' => 5],
        'is_substantiated' => true,
    ]);
    // Why Choose Us — real differentiators from the narrative.
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'differentiators' => [['title' => 'Preventive-first', 'description' => 'We stop failures before they happen.']],
    ]);
    // Service areas — priority + coverage markets.
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Jersey City', 'tier' => MarketTier::Priority]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'tier' => MarketTier::Coverage]);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose(
        $home->fresh(), $home->slot_payload,
        ['hero_image' => ['url' => 'https://cdn.example/hero.webp', 'alt' => 'On site']],
    );

    expect($markup)
        ->toContain('NJ Master Plumber')                                        // credibility strip
        ->toContain('Preventive-first')                                         // why choose us
        ->toContain('Getting started is simple')                                // how it works (always)
        ->toContain('Caught a collapsing line before it flooded the basement.') // testimonial
        ->toContain('★★★★★')                                                     // star rating rendered
        ->toContain('Facilities Director')
        ->toContain('Jersey City')->toContain('Newark')                         // service-area tags
        ->toContain('Areas we serve');
});

it('the areas section leads with the named counties, then lists towns largest-first', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013', '34017']]);

    // County names resolve via the SAME gazetteer seam onboarding's county multi-select uses.
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('countiesInState')->with('34')->andReturn([
        new County('34013', 'Essex County', '34', '013'),
        new County('34017', 'Hudson County', '34', '017'),
        new County('34099', 'Ocean County', '34', '099'), // in-state but NOT selected
    ]);
    app()->instance(MunicipalityGazetteer::class, $gazetteer);

    // Coverage towns carry the census size tier → ordered major → medium → small.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Tinytown', 'size_tier' => 'small', 'population' => 1500]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'population' => 300000]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Bloomfield', 'size_tier' => 'medium', 'population' => 50000]);
    // Newark has a real location page → its pill links; Tinytown has none → plain pill (no invented URL).
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'slug' => 'newark', 'title' => 'Newark']);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Serving Essex County and Hudson County.') // named from the SELECTED county geoids
        ->not->toContain('Ocean County')                       // an unselected in-state county is excluded
        ->toContain('Areas we serve')
        ->toContain('href="https://sewergurus.com/newark"');   // real town-page link

    // Largest-first: major before medium before small.
    expect(mb_strpos($markup, 'Newark'))->toBeLessThan(mb_strpos($markup, 'Bloomfield'));
    expect(mb_strpos($markup, 'Bloomfield'))->toBeLessThan(mb_strpos($markup, 'Tinytown'));
});

it('service cards carry a real description even without SEO meta — from the page slots', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'title' => 'Drain Cleaning',
        'slot_payload' => ['hero_subhead' => 'Snaking and hydro-jetting that clears the whole pipe wall.'],
        'meta' => [], // no SEO meta_description
    ]);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Drain Cleaning')
        ->toContain('Snaking and hydro-jetting that clears the whole pipe wall.'); // real blurb, not just "Learn more"
});

it('an ungenerated service page gets a generated, keyword-grounded card blurb (cached, never null)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump maintenance']);

    // The child page exists (its card links to it) but is NOT generated: no SEO, no slots.
    $service = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'sump-pump-maintenance', 'title' => 'Sump Pump Maintenance',
        'slot_payload' => [], 'meta' => [], 'target_keyword_id' => $keyword->id,
    ]);

    $fake = new FakeClaudeClient('Routine upkeep that keeps your sump pump ready before the next big storm.');
    app()->instance(ClaudeClient::class, $fake);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Sump Pump Maintenance')
        ->toContain('Routine upkeep that keeps your sump pump ready before the next big storm.');
    // Grounded on the real keyword (the §5 carry-over), not generic.
    expect($fake->prompts[0])->toContain('sump pump maintenance');
    // Generated once → cached on the page so a re-publish reuses it (no second model call).
    expect($service->fresh()->meta['card_blurb'] ?? null)
        ->toBe('Routine upkeep that keeps your sump pump ready before the next big storm.');
});

it('a card blurb is never null even when the model is unreachable — deterministic keyword template', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'hydro jetting']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'hydro-jetting', 'title' => 'Hydro Jetting',
        'slot_payload' => [], 'meta' => [], 'target_keyword_id' => $keyword->id,
    ]);

    // A model that throws → the resolver must still return a non-empty, keyword-anchored line.
    app()->instance(ClaudeClient::class, new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('model down');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('model down');
        }
    });

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Hydro Jetting')
        ->toContain('Hydro jetting'); // the deterministic template leads with the keyword — never blank
});

it('How It Works uses the tenant process when captured (else the safe default)', function () {
    $site = Site::factory()->create();
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Process, 'payload' => ['title' => 'Book a camera inspection', 'description' => 'We scope the line first — no guessing.']]);
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Process, 'payload' => ['title' => 'Fixed-price plan', 'description' => 'You approve the number before we dig.']]);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Book a camera inspection')
        ->toContain('Fixed-price plan')
        ->not->toContain('Free assessment'); // the generic default is replaced by the real process
});

it('data-gated sections stay hidden when their data is absent — degrade, never fabricate', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->not->toContain('What sets us apart')   // no differentiators → no Why section
        ->not->toContain('In their words')       // no reviews → no Testimonials
        ->not->toContain('Areas we serve')       // no markets → no Service Areas
        // but the presentational process section always renders
        ->toContain('Getting started is simple');
});

it('preview builds ALL recommended sections with labeled placeholders; publish omits the empty ones', function () {
    // A bare tenant: no badges, no differentiators, no reviews, no markets — every data-gated section empty.
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $preview = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, [], preview: true);
    $published = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    // Preview: the whole page is visible — every data-gated section renders as a labeled example.
    expect($preview)
        ->toContain('lp-placeholder')                                   // sections greyed as examples
        ->toContain('What sets us apart')                               // Why Choose Us shown
        ->toContain('In their words')                                   // Testimonials shown
        ->toContain('Areas we serve')                                   // Service Areas shown
        ->toContain('activates when you add reviews')                   // names what fills Testimonials
        ->toContain('activates when you add licenses, certifications, or ratings') // Credibility
        ->toContain('add your service areas to activate this section')  // Service Areas
        ->toContain('Getting started is simple');                       // always-on section still there

    // Publish (default): the same empty sections are GONE — data-gated, no placeholder leaks live.
    expect($published)
        ->not->toContain('lp-placeholder')
        ->not->toContain('What sets us apart')
        ->not->toContain('In their words')
        ->not->toContain('Areas we serve')
        ->not->toContain('activates when you add')
        ->toContain('Getting started is simple');                       // always-on unaffected
});

it('a section WITH data renders real in preview — no placeholder marker on it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    // Real testimonial present; other data-gated sections still empty.
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'They saved us from a flooded basement.', 'author' => 'Real Client', 'stars' => 5],
        'is_substantiated' => true,
    ]);
    $home = blockHomePage($site);

    $preview = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, [], preview: true);

    expect($preview)
        ->toContain('They saved us from a flooded basement.')  // the REAL review renders
        ->not->toContain('activates when you add reviews')     // so Testimonials is NOT a placeholder
        ->toContain('add your service areas to activate this section'); // but empty Service Areas still is
});

it('the meta-blob threads preview into post_content (placeholder in preview, gated on publish)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $previewBlob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect(), preview: true);
    $publishBlob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect());

    expect($previewBlob['post_content'])->toContain('lp-placeholder')->toContain('Areas we serve');
    expect($publishBlob['post_content'])->not->toContain('lp-placeholder')->not->toContain('Areas we serve');
});

it('returns null for a page type whose block pattern has not shipped (falls back to existing render)', function () {
    $site = Site::factory()->create();
    $service = blockServicePage($site, 'Drain Cleaning', 'drain-cleaning', 'x');

    expect(app(BlockContentAssembler::class)->compose($service->fresh(), [], []))->toBeNull();
});

it('the areas section leads with the map mount + keeps the text fallback when geometry exists', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013', '34017']]);

    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->with('34')->andReturn([
        new County('34013', 'Essex County', '34', '013'),
        new County('34017', 'Hudson County', '34', '017'),
    ]);
    $gaz->shouldReceive('countyPolygons')->andReturn([
        ['geo_id' => '34013', 'name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2]]]],
        ['geo_id' => '34017', 'name' => 'Hudson County', 'rings' => [[['lat' => 40.7, 'lng' => -74.05]]]],
    ]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17]);

    $home = blockHomePage($site);

    // Compose with the map available → the mount + the crawlable text fallback both render; under the
    // map the counties are a compact pipe-separated caption (not the "Serving …" sentence).
    $withMap = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, [], mapAvailable: true);
    expect($withMap)
        ->toContain('class="lp-areas-map"')             // the Leaflet mount point
        ->toContain('lp-areas--map')                    // section modifier
        ->toContain('Essex County | Hudson County')     // pipe-separated county caption
        ->not->toContain('Serving Essex County')        // no natural sentence under the map
        ->toContain('Newark');                          // town pill stays

    // Compose without the map → no mount, and the counties read as the natural sentence (back-compat).
    $noMap = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);
    expect($noMap)->not->toContain('lp-areas-map')
        ->toContain('Serving Essex County and Hudson County.');
});

it('the meta-blob carries the service_area_map geometry for Home (and drives the mount)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013']]);

    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturn([new County('34013', 'Essex County', '34', '013')]);
    $gaz->shouldReceive('countyPolygons')->andReturn([['geo_id' => '34013', 'name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2]]]]]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17]);

    $blob = app(MetaBlobAssembler::class)->assemble(blockHomePage($site)->fresh(), collect());

    expect($blob)->toHaveKey('service_area_map')
        ->and($blob['service_area_map'])->not->toBeNull()
        ->and($blob['service_area_map']['counties'][0]['name'])->toBe('Essex County')
        ->and($blob['service_area_map']['cities'][0]['name'])->toBe('Newark')
        ->and($blob['post_content'])->toContain('class="lp-areas-map"');
});

it('the service_area_map is null for a tenant with no coverage (map self-prunes, text data-gates)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);

    $blob = app(MetaBlobAssembler::class)->assemble(blockHomePage($site)->fresh(), collect());

    expect($blob['service_area_map'])->toBeNull()
        ->and($blob['post_content'])->not->toContain('lp-areas-map');
});

it('the meta-blob carries post_content for Home', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $blob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('Stop sewer problems');
});
