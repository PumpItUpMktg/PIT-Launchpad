<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
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
    // Service areas — a served county with a covered town (county subdivision → county via GEOID prefix).
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34017']]);
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturn([new County('34017', 'Hudson County', '34', '017')]);
    $gaz->shouldReceive('countyPolygons')->andReturn([]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Jersey City', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '3401736000', 'size_tier' => 'major', 'population' => 290000,
    ]);

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
        ->toContain('Areas we serve')
        ->toContain('Hudson County')                                            // county subhead + pipe line
        ->toContain('Jersey City');                                             // major city grouped under it
});

it('groups the major cities under each county, largest-first, county names in the subheads', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013', '34017']]);

    // County names resolve via the SAME gazetteer seam onboarding's county multi-select uses.
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('countiesInState')->with('34')->andReturn([
        new County('34013', 'Essex County', '34', '013'),
        new County('34017', 'Hudson County', '34', '017'),
        new County('34099', 'Ocean County', '34', '099'), // in-state but NOT selected
    ]);
    $gazetteer->shouldReceive('countyPolygons')->andReturn([]); // subdivisions group by GEOID prefix
    app()->instance(MunicipalityGazetteer::class, $gazetteer);

    // County subdivisions: the first 5 GEOID digits are the county — 34013 = Essex, 34017 = Hudson.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Tinytown', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401399999', 'size_tier' => 'small', 'population' => 1500]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401351000', 'size_tier' => 'major', 'population' => 300000]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Bloomfield', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401306260', 'size_tier' => 'medium', 'population' => 50000]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Jersey City', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401736000', 'size_tier' => 'major', 'population' => 290000]);
    // Newark has a real location page → its name links; the rest are plain (no invented URL).
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'slug' => 'newark', 'title' => 'Newark']);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('Areas we serve')
        ->toContain('Essex County')->toContain('Hudson County') // county names as group subheads
        ->not->toContain('Essex County | Hudson County')        // no separate pipe county line
        ->not->toContain('Ocean County')                        // an unselected in-state county is excluded
        ->toContain('Jersey City')                              // Hudson's town, grouped
        ->toContain('href="https://sewergurus.com/newark"');    // real town-page link

    // Within Essex: largest-first (major → medium → small).
    expect(mb_strpos($markup, 'Newark'))->toBeLessThan(mb_strpos($markup, 'Bloomfield'));
    expect(mb_strpos($markup, 'Bloomfield'))->toBeLessThan(mb_strpos($markup, 'Tinytown'));
    // Counties ordered by name: Essex county block before Hudson's.
    expect(mb_strpos($markup, 'Essex County'))->toBeLessThan(mb_strpos($markup, 'Hudson County'));
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

it('the home phone falls back to the site business phone when no location has one', function () {
    // A guided-onboarded tenant: business phone on the Site, no Location phone anywhere.
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'phone' => '(973) 555-0100']);
    $home = blockHomePage($site);

    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)->toContain('tel:9735550100'); // the hero Call button gets the site business number
});

it('the emergency call-now line uses the dedicated emergency number when set', function () {
    $site = Site::factory()->create([
        'domain_url' => 'https://sewergurus.com', 'offers_emergency' => true,
        'phone' => '(973) 555-0100', 'emergency_phone' => '(973) 555-9111',
    ]);
    $home = blockHomePage($site);

    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('tel:9735550100')   // main number leads (hero)
        ->toContain('Or call now:')      // the emergency CTA line renders
        ->toContain('tel:9735559111');   // and it dials the dedicated emergency line
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
        ->not->toContain('Areas we serve')       // no coverage → no Service Areas
        // but the presentational process section always renders
        ->toContain('Getting started is simple');
});

it('preview builds ALL recommended sections with labeled placeholders; publish omits the empty ones', function () {
    // A bare tenant: no badges, no differentiators, no reviews, no coverage — every data-gated section empty.
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

it('renders the certifications row + guarantee band VERBATIM from the narrative (never fabricated)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'guarantee' => ['name' => 'Forever Pump Warranty', 'description' => 'If the pump fails, we replace it — free, for life.'],
        'certifications' => [
            ['label' => 'NJ Master Plumber', 'number' => '#1234'],
            ['label' => 'BBB A+ Rated'],
        ],
    ]);
    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)
        ->toContain('lp-certs')                                              // certifications row present
        ->toContain('NJ Master Plumber')->toContain('#1234')                 // real credential + number, verbatim
        ->toContain('BBB A+ Rated')                                          // per-item — both shown
        ->toContain('lp-guarantee')                                         // guarantee band present
        ->toContain('Forever Pump Warranty')                                // the guarantee name, verbatim
        ->toContain('If the pump fails, we replace it — free, for life.');  // its description, verbatim
});

it('omits the guarantee band + certifications row when none are captured (degrade, never invent a credential)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    expect($markup)->not->toContain('lp-guarantee')->not->toContain('lp-certs');
});

it('preview shows the guarantee + certifications as labeled placeholders; publish omits them', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $preview = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-guarantee')->toContain('appears when you add a guarantee')
        ->toContain('lp-certs')->toContain('appears when you add certifications');

    $publish = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);
    expect($publish)->not->toContain('lp-guarantee')->not->toContain('lp-certs');
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
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401351000', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17]);

    $home = blockHomePage($site);

    // Map available → the 50/50 mount + the grouped cities (county names in subheads) render.
    $withMap = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, [], mapAvailable: true);
    expect($withMap)
        ->toContain('class="lp-areas-map"')  // the Leaflet mount point
        ->toContain('lp-areas--map')         // section modifier
        ->toContain('lp-areas-split')        // the 50/50 columns
        ->toContain('Essex County')          // county name as a group subhead
        ->toContain('Newark');               // major city grouped under Essex

    // No map → no mount; the grouped cities still render (crawlable).
    $noMap = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);
    expect($noMap)->not->toContain('lp-areas-map')
        ->toContain('Essex County')
        ->toContain('Newark');
});

it('the meta-blob carries the service_area_map geometry for Home (and drives the mount)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013']]);

    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturn([new County('34013', 'Essex County', '34', '013')]);
    $gaz->shouldReceive('countyPolygons')->andReturn([['geo_id' => '34013', 'name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2]]]]]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401351000', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17]);

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

// A Why Choose Us page: page_type Utility, standard_type why_choose_us, hero drafted into slots.
function blockWhyChooseUsPage(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::WhyChooseUs->value,
        'slug' => 'why-choose-us',
        'title' => 'Why Choose Us',
        'slot_payload' => [
            'hero_headline' => 'Preventive-first plumbing that saves you money.',
            'hero_subhead' => 'The reasons commercial buildings across NJ trust us.',
        ],
    ]);
}

it('composes the Why Choose Us page from real §1 data — differentiators spine, guarantee, certs, client voice', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'differentiators' => [
            ['title' => 'Preventive-first', 'description' => 'We stop failures before they happen.'],
            ['title' => 'Licensed crew', 'description' => 'Every tech is a NJ-licensed plumber.'],
        ],
        'guarantee' => ['name' => 'Forever Pump Warranty', 'description' => 'If the pump fails, we replace it — free, for life.'],
        'certifications' => [['label' => 'NJ Master Plumber', 'number' => '#1234']],
    ]);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'They caught a collapsing line before it flooded us.', 'author' => 'Facilities Director', 'stars' => 5],
        'is_substantiated' => true,
    ]);

    $page = blockWhyChooseUsPage($site);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('Preventive-first plumbing that saves you money.')  // hero (drafted headline)
        ->toContain('Reasons clients choose us')                        // the WCU heading
        ->toContain('Preventive-first')->toContain('Licensed crew')     // differentiators — the page spine
        ->toContain('lp-guarantee')->toContain('Forever Pump Warranty') // guarantee band, verbatim
        ->toContain('lp-certs')->toContain('NJ Master Plumber')->toContain('#1234') // certs, verbatim
        ->toContain('They caught a collapsing line before it flooded us.')          // substantiated client voice
        ->toContain('Ready to get started?')                           // closing CTA
        // this page argues WHY, not WHAT — no services grid / how-it-works / areas
        ->not->toContain('Getting started is simple')
        ->not->toContain('Areas we serve');
});

it('Why Choose Us: preview builds every section with labeled placeholders; publish data-gates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockWhyChooseUsPage($site); // no §1 differentiators / guarantee / certs captured

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-why')->toContain('activates when you add what sets you apart')
        ->toContain('lp-guarantee')->toContain('appears when you add a guarantee')
        ->toContain('lp-certs')->toContain('appears when you add certifications');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-why')          // no differentiators → the spine section omits on publish
        ->not->toContain('lp-guarantee')
        ->not->toContain('lp-certs')
        // ...but the hero + CTA always render, so a bare page still ships something honest
        ->toContain('Preventive-first plumbing that saves you money.')
        ->toContain('Ready to get started?');
});

it('returns null for a standard page whose composer has not shipped (Contact falls back to existing render)', function () {
    $site = Site::factory()->create();
    $contact = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Utility, 'standard_type' => StandardPageType::Contact->value,
        'slug' => 'contact', 'title' => 'Contact',
    ]);

    expect(app(BlockContentAssembler::class)->compose($contact->fresh(), [], []))->toBeNull();
});

it('the meta-blob carries post_content for the Why Choose Us page (end-to-end publish path)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'differentiators' => [['title' => 'Preventive-first', 'description' => 'We stop failures before they happen.']],
    ]);
    $page = blockWhyChooseUsPage($site);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('Reasons clients choose us')
        ->and($blob['post_content'])->toContain('Preventive-first')
        ->and($blob['service_area_map'])->toBeNull(); // areas map is home-only
});

// An About page: page_type Utility, standard_type about, hero + drafted story/mission in slots.
function blockAboutPage(Site $site, array $slots = []): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::About->value,
        'slug' => 'about',
        'title' => 'About',
        'slot_payload' => array_merge([
            'hero_headline' => 'Family-run plumbing, three generations deep.',
        ], $slots),
    ]);
}

it('composes the About page — drafted story + mission, §1 values, real team, credibility', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'story' => 'Raw captured story — should be overridden by the drafted prose.',
        'mission' => 'Raw mission.',
        'values' => [
            ['title' => 'Show up on time', 'description' => 'Every appointment, every time.'],
            ['title' => 'Leave it clean', 'description' => 'Cleaner than we found it.'],
        ],
        'team' => [
            ['name' => 'Dana Rivera', 'role' => 'Master Plumber', 'bio' => 'Twenty years in the trade.'],
        ],
    ]);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::License,
        'payload' => ['label' => 'NJ Master Plumber'], 'is_substantiated' => true,
    ]);

    $page = blockAboutPage($site, [
        'our_story' => '<p>We started in a single truck in 1987.</p><p>Today we serve all of Northern NJ.</p>',
        'mission' => 'Keep every home’s water running — honestly and on time.',
    ]);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('Family-run plumbing, three generations deep.')  // hero (drafted headline)
        ->toContain('Who we are')                                    // story section head
        ->toContain('We started in a single truck in 1987.')         // drafted story, split into paragraphs
        ->toContain('Today we serve all of Northern NJ.')
        ->not->toContain('Raw captured story')                       // drafted prose WINS over raw §1
        ->toContain('lp-statement')->toContain('Keep every home’s water running — honestly and on time.') // mission band
        ->toContain('What we stand for')->toContain('Show up on time')->toContain('Leave it clean')        // values grid (§1)
        ->toContain('The people behind the work')->toContain('Dana Rivera')->toContain('Master Plumber')    // team grid (§1)
        ->toContain('DR')                                            // initials avatar (no photo captured)
        ->toContain('NJ Master Plumber');                           // credibility strip
});

it('About story + mission fall back to the raw §1 narrative when the drafter has not filled the slots', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'story' => 'We are a family shop serving the county since 1990.',
        'mission' => 'Treat every home like our own.',
        'values' => [], 'team' => null,
    ]);

    $page = blockAboutPage($site); // no our_story / mission slots
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('We are a family shop serving the county since 1990.') // §1 story fallback
        ->toContain('Treat every home like our own.');                     // §1 mission fallback
});

it('About: preview builds every section with labeled placeholders; publish data-gates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockAboutPage($site); // no §1 narrative captured at all

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-story')->toContain('appears when you add your story')
        ->toContain('lp-statement')->toContain('appears when you add your mission')
        ->toContain('lp-values')->toContain('appears when you add your values')
        ->toContain('lp-team')->toContain('appears when you add your team');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-story')->not->toContain('lp-statement')
        ->not->toContain('lp-values')->not->toContain('lp-team')
        // ...hero + CTA always render, so a bare About still ships something honest
        ->toContain('Family-run plumbing, three generations deep.')
        ->toContain('Let’s work together');
});

it('the meta-blob carries post_content for the About page (end-to-end publish path)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'values' => [['title' => 'Show up on time', 'description' => 'Every appointment, every time.']],
        'team' => null,
    ]);
    $page = blockAboutPage($site, ['our_story' => 'We started in a single truck in 1987.']);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('Who we are')
        ->and($blob['post_content'])->toContain('We started in a single truck in 1987.')
        ->and($blob['post_content'])->toContain('Show up on time')
        ->and($blob['service_area_map'])->toBeNull();
});
