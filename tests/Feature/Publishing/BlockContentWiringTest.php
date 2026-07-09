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
use App\Models\VoiceProfile;
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
        ->toContain('NJ Master Plumber')                                        // substantiated credential (merged trust band)
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
        ->toContain('appears when you add certifications')              // the single merged credentials band
        ->not->toContain('lp-credibility')                             // NO separate credibility strip on Home anymore
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

it('Home shows ONE trust band — captured certs + substantiated proof credentials, merged and deduped (no duplicate section)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    // Narrative certification AND a substantiated proof with the SAME label (entered in both places).
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'certifications' => [['label' => 'NJ Master Plumber', 'number' => '#1234']],
    ]);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::License,
        'payload' => ['label' => 'NJ Master Plumber'], 'is_substantiated' => true, // duplicate of the cert above
    ]);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Cert,
        'payload' => ['label' => 'EPA Certified'], 'is_substantiated' => true,      // proof-only credential
    ]);

    $home = blockHomePage($site);
    $markup = app(BlockContentAssembler::class)->compose($home->fresh(), $home->slot_payload, []);

    // Isolate the credentials band (between its section marker and the next section) to prove the dedup
    // there, independent of the hero trust-stat row (which separately surfaces substantiated labels).
    $band = (string) strstr($markup, 'lp-certs', false);
    $band = substr($band, 0, (int) strpos($band, 'lp-services') ?: strlen($band));

    expect($markup)
        ->toContain('lp-certs')                                        // the single credentials band
        ->not->toContain('lp-credibility')                            // NO separate credibility strip
        ->toContain('#1234')                                          // the captured cert (with its number)
        ->toContain('EPA Certified');                                 // the proof-only credential, folded in
    // the duplicate "NJ Master Plumber" (in BOTH the cert and the proof) renders exactly once in the band
    expect(substr_count($band, 'NJ Master Plumber'))->toBe(1);
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
    // Location pages are the next migration phase — still on the Elementor fallback for now.
    $location = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'slug' => 'trenton', 'title' => 'Trenton',
    ]);

    expect(app(BlockContentAssembler::class)->compose($location->fresh(), [], []))->toBeNull();
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

it('every content-rich page has TWO CTAs — a pushy one (cta1) and a softer one (cta2)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'phone' => '(973) 555-0100']);

    // The content-rich CONVERSION pages have enough light sections to hold the pushy accent band apart
    // from every other colored band, so they carry both CTAs. (About is deliberately absent: an About
    // visitor is evaluating, not converting — it carries ONE soft consultative CTA.)
    foreach ([blockHomePage($site), blockWhyChooseUsPage($site), blockServiceDrafted($site)] as $page) {
        $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);

        expect($markup)
            ->toContain('lp-cta--bold')       // cta1 — the pushy accent band, blatantly asking for the business
            ->toContain('Get a free quote')   // its pushy ask
            ->toContain('Get in touch')       // cta2 — the softer, info-seeking ask
            ->toContain('has-accent-background-color'); // the bold band renders on the accent colour
    }
});

it('a thin utility page carries ONE band CTA (the soft close) — no second colored band with nothing to separate it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'phone' => '(973) 555-0100']);

    // FAQ / Areas / Contact are short: a pushy accent band would sit against the dark hero or the dark
    // soft-close with no light section between. So they drop it — the hero button + the soft CTA carry
    // the conversion, and no two colored bands are ever adjacent.
    foreach ([blockFaqPage($site, [['question' => 'Q', 'answer' => 'A']]), blockAreasPage($site), blockContactPage($site)] as $page) {
        $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);

        expect($markup)
            ->not->toContain('lp-cta--bold')  // no pushy accent band
            ->toContain('lp-cta')             // the soft close still renders
            ->toContain('Get in touch');      // its info-seeking ask
    }
});

it('legal pages get NO sales CTA (a utility page is not a conversion surface)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'phone' => '(973) 555-0100']);
    $page = blockLegalPage($site, StandardPageType::Privacy);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->not->toContain('lp-cta');
});

it('the meta-blob carries post_content for Home', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $blob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('Stop sewer problems');
});

it('a block page carries NO Elementor body — it is pure Gutenberg (elementor_data is empty)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $blob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect());

    // post_content present → the NativeComposer Elementor pass is skipped entirely.
    expect($blob['post_content'])->not->toBeNull()
        ->and($blob['elementor_data'])->toBe([]);
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
        ->toContain('Ready to get it fixed?')                          // the pushy CTA (cta1)
        ->toContain('Have a question first?')                          // the softer CTA (cta2)
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
        // ...but the hero + CTAs always render, so a bare page still ships something honest
        ->toContain('Preventive-first plumbing that saves you money.')
        ->toContain('Ready to get it fixed?');
});

it('returns null for a standard page whose composer has not shipped (Gallery falls back to existing render)', function () {
    $site = Site::factory()->create();
    $gallery = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Utility, 'standard_type' => StandardPageType::Gallery->value,
        'slug' => 'gallery', 'title' => 'Gallery',
    ]);

    expect(app(BlockContentAssembler::class)->compose($gallery->fresh(), [], []))->toBeNull();
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
        ->toContain('Our promises to you')->toContain('Show up on time')->toContain('Leave it clean')      // values grid (§1)
        ->toContain('The people behind the work')->toContain('Dana Rivera')->toContain('Master Plumber')    // team grid (§1)
        ->toContain('DR')                                            // initials avatar (no photo captured)
        ->toContain('NJ Master Plumber');                           // credibility strip
});

it('About story falls back to the client\'s own §1 prose — but the MISSION never renders raw intake', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'story' => 'We are a family shop serving the county since 1990.',
        // The canonical raw-intake leak: the operator's brief/keywords typed into the mission field.
        'mission' => 'prevention vs emergency, pragmatic approach, prevention of emergency service calls',
        'values' => [], 'team' => null,
    ]);

    $page = blockAboutPage($site); // undrafted — no our_story / mission slots
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('We are a family shop serving the county since 1990.')  // story: the client's OWN prose is safe
        ->not->toContain('prevention vs emergency')                          // mission: the raw brief NEVER leaks
        ->not->toContain('lp-statement');                                    // the band omits until drafted
});

it('About renders the drafted mission as composed prose (and values prefer the drafted promise framing)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'mission' => 'prevention vs emergency, pragmatic approach',                      // the raw brief
        'values' => [['title' => 'On time', 'description' => 'raw label line']],         // the raw labels
    ]);

    $page = blockAboutPage($site, [
        'mission' => 'We keep buildings running by preventing emergencies before they start.', // drafted
        'values' => ['Camera-first honesty — we show you the problem on camera before we quote'], // drafted promise
    ]);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('We keep buildings running by preventing emergencies before they start.')
        ->not->toContain('prevention vs emergency')                          // drafted prose wins, raw never leaks
        ->toContain('Camera-first honesty')                                  // "Title — line" split into the card
        ->toContain('we show you the problem on camera before we quote')
        ->not->toContain('raw label line');                                  // drafted promises beat the raw labels
});

it('About shows the why-us differentiator CARDS (the home pattern) and exactly ONE soft CTA', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'differentiators' => [['title' => 'Preventive-first', 'description' => 'We stop failures before they happen.']],
    ]);

    $page = blockAboutPage($site);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('lp-why')->toContain('Preventive-first')                 // differentiators as visual cards
        ->not->toContain('lp-cta--bold')                                     // no pushy band on About
        ->toContain('Have a question first?');                               // the single consultative close
    expect(substr_count($markup, 'lp-cta'))->toBe(1);                        // exactly one CTA band class token
});

it('About credibility order follows the audience — commercial leads certifications, homeowner leads reviews', function () {
    $makeProof = function (Site $site): void {
        // Captured in "wrong" created order on purpose: review first, cert last.
        ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::ReviewAggregate, 'payload' => ['label' => '4.9★ on Google'], 'is_substantiated' => true]);
        ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::License, 'payload' => ['label' => 'NJ Master Plumber'], 'is_substantiated' => true]);
        ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Cert, 'payload' => ['label' => 'Backflow Certified'], 'is_substantiated' => true]);
    };

    // The hero trust row also shows proof labels (created-at order), so the ordering assertion scopes
    // to the credibility STRIP itself.
    $strip = fn (string $markup): string => substr($markup, (int) strpos($markup, 'lp-credibility'));

    // Commercial audience (from the active voice profile) → certifications lead.
    $commercial = Site::factory()->create(['domain_url' => 'https://a.example']);
    $makeProof($commercial);
    VoiceProfile::factory()->active()->create(['site_id' => $commercial->id, 'audience' => ['primary' => 'commercial building owners and facility managers']]);
    $markup = $strip(app(BlockContentAssembler::class)->compose(blockAboutPage($commercial)->fresh(), [], []));
    expect(strpos($markup, 'Backflow Certified'))->toBeLessThan(strpos($markup, '4.9★ on Google'));

    // Homeowner (the default, no voice captured) → reviews lead.
    $homeowner = Site::factory()->create(['domain_url' => 'https://b.example']);
    $makeProof($homeowner);
    $markup = $strip(app(BlockContentAssembler::class)->compose(blockAboutPage($homeowner)->fresh(), [], []));
    expect(strpos($markup, '4.9★ on Google'))->toBeLessThan(strpos($markup, 'Backflow Certified'));
});

it('the blob carries the FINER page identity — About sends page_type "about", never the shared "utility" bucket', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);

    $about = app(MetaBlobAssembler::class)->assemble(blockAboutPage($site)->fresh(), collect());
    expect($about['page_type'])->toBe('about');                              // lp-page-type-about, not -utility

    $home = app(MetaBlobAssembler::class)->assemble(blockHomePage($site)->fresh(), collect());
    expect($home['page_type'])->toBe('home');                                // the plugin's front-page check is untouched
});

it('About: preview builds every section with labeled placeholders; publish data-gates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockAboutPage($site); // no §1 narrative captured at all

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-story')->toContain('appears when you add your story')
        ->toContain('lp-statement')->toContain('appears when a mission is captured and the page is generated')
        ->toContain('lp-values')->toContain('appears when you add your values')
        ->toContain('lp-team')->toContain('appears when you add your team');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-story')->not->toContain('lp-statement')
        ->not->toContain('lp-values')->not->toContain('lp-team')
        // ...hero + CTAs always render, so a bare About still ships something honest
        ->toContain('Family-run plumbing, three generations deep.')
        ->toContain('Have a question first?');
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

// A FAQ page: page_type Utility, standard_type faq, drafted Q&A in the `faq` slot (+ hero + intro).
function blockFaqPage(Site $site, array $faq = []): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::Faq->value,
        'slug' => 'faq',
        'title' => 'FAQ',
        'slot_payload' => [
            'hero_headline' => 'Plumbing questions, answered',
            'intro' => 'The answers we give most often — no jargon.',
            'faq' => $faq,
        ],
    ]);
}

it('composes the FAQ page — a native <details> accordion from the drafted Q&A pairs', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockFaqPage($site, [
        ['question' => 'How soon can you come out?', 'answer' => 'Most calls are handled the same day.'],
        ['question' => 'Do you charge for estimates?', 'answer' => 'No — estimates are always free.'],
        ['question' => '', 'answer' => 'dropped'],            // incomplete pair is filtered out
    ]);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('Plumbing questions, answered')          // hero headline
        ->toContain('<details class="lp-faq">')              // native accordion, kses-safe
        ->toContain('lp-faq__q')->toContain('lp-faq__a')     // plugin class contract
        ->toContain('How soon can you come out?')->toContain('Most calls are handled the same day.')
        ->toContain('Do you charge for estimates?')
        ->not->toContain('>dropped<')                        // the incomplete pair never renders
        ->toContain('Still have a question?');               // closing CTA
});

it('FAQ: preview shows the accordion with a labeled example; publish omits it when no Q&A drafted', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockFaqPage($site); // no faq pairs

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-faqs')->toContain('appears when you add your FAQs')
        ->toContain('<details class="lp-faq">');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-faq-list')                      // the accordion omits on publish
        // ...hero + CTA still render, so a bare FAQ ships something honest
        ->toContain('Plumbing questions, answered')
        ->toContain('Still have a question?');
});

it('the meta-blob carries the FAQ post_content AND slot_payload.faq (the key the plugin schema reads)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockFaqPage($site, [
        ['question' => 'How soon can you come out?', 'answer' => 'Most calls are handled the same day.'],
    ]);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('How soon can you come out?')
        // the resolved slot_payload keeps `faq` — the plugin emits FAQPage schema from it
        ->and($blob['slot_payload']['faq'][0]['question'] ?? null)->toBe('How soon can you come out?');
});

// An Areas We Serve page: page_type Utility, standard_type areas_we_serve, hero + intro drafted.
function blockAreasPage(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::AreasWeServe->value,
        'slug' => 'areas-we-serve',
        'title' => 'Areas We Serve',
        'slot_payload' => [
            'hero_headline' => 'Serving all of Northern New Jersey',
            'intro' => 'Find your town below — and if you don’t see it, just ask.',
        ],
    ]);
}

// A served county + a covered major town, wired through the same gazetteer seam the home areas use.
function seedAreasCoverage(Site $site): void
{
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013']]);
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturn([new County('34013', 'Essex County', '34', '013')]);
    $gaz->shouldReceive('countyPolygons')->andReturn([
        ['geo_id' => '34013', 'name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2]]]],
    ]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Newark', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '3401351000', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17,
    ]);
}

it('composes the Areas We Serve page — reuses the serviceAreas block (map + counties + cities) from §1', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    seedAreasCoverage($site);

    $page = blockAreasPage($site);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], mapAvailable: true);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('Serving all of Northern New Jersey')  // hero (drafted headline)
        ->toContain('The towns and counties we cover')     // areas section heading
        ->toContain('class="lp-areas-map"')                // interactive map mount (mapAvailable)
        ->toContain('Essex County')                        // real served county
        ->toContain('Newark')                              // major city grouped under it
        ->toContain('Don’t see your town?');               // closing CTA
});

it('the meta-blob emits service_area_map for the Areas We Serve page (not just home)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    seedAreasCoverage($site);

    $blob = app(MetaBlobAssembler::class)->assemble(blockAreasPage($site)->fresh(), collect());

    expect($blob['service_area_map'])->not->toBeNull()
        ->and($blob['service_area_map']['counties'][0]['name'])->toBe('Essex County')
        ->and($blob['service_area_map']['cities'][0]['name'])->toBe('Newark')
        ->and($blob['post_content'])->toContain('class="lp-areas-map"');
});

it('Areas We Serve: preview shows an example territory; publish omits coverage when none exists', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $page = blockAreasPage($site); // no coverage seeded

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)->toContain('lp-areas')->toContain('lp-placeholder');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-placeholder')                 // never a placeholder on the live page
        ->toContain('Serving all of Northern New Jersey')  // hero + CTA still render
        ->toContain('Don’t see your town?');
});

// A legal page (Privacy / Terms): page_type Utility, standard_type, only a title slot.
function blockLegalPage(Site $site, StandardPageType $type): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => $type->value,
        'slug' => $type->value,
        'title' => $type->label(),
        'slot_payload' => ['hero_headline' => $type->label()],
    ]);
}

it('composes the Privacy Policy from a real template filled with tenant data (never AI-generated)', function () {
    $site = Site::factory()->create(['brand_name' => 'Sewer Gurus', 'domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'email' => 'hello@sewergurus.com']);

    $page = blockLegalPage($site, StandardPageType::Privacy);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('lp-legal')
        ->toContain('Privacy Policy')
        ->toContain('Effective date:')
        ->toContain('Sewer Gurus')                                  // real business name, filled in
        ->toContain('sewergurus.com')                               // the site host, from the domain
        ->toContain('hello@sewergurus.com')                         // captured contact email, not invented
        ->toContain('Information we collect')                       // real template section
        ->toContain('We do not sell your personal information.')    // honest, universal statement
        // it is a plain document — no marketing hero / CTA
        ->not->toContain('lp-hero')->not->toContain('lp-cta');
});

it('composes the Terms of Service with generic governing-law phrasing (no fabricated state)', function () {
    $site = Site::factory()->create(['brand_name' => 'Sewer Gurus', 'domain_url' => 'https://sewergurus.com']);

    $page = blockLegalPage($site, StandardPageType::Terms);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('Terms of Service')
        ->toContain('Acceptance of terms')
        ->toContain('the laws of the state in which Sewer Gurus operates')  // generic — never guesses a state
        ->toContain('as is')                                                // standard warranty disclaimer (quotes are html-escaped)
        // no contact channel captured → points at the contact page, never invents an address
        ->toContain('contact page on this website');
});

it('legal templates degrade honestly — no brand name → "this business", no contact → the contact page', function () {
    $site = Site::factory()->create(['brand_name' => '', 'domain_url' => null]);

    $page = blockLegalPage($site, StandardPageType::Privacy);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('this business')          // graceful fallback for a missing brand
        ->toContain('this website')           // graceful fallback for a missing site URL
        ->toContain('contact page on this website'); // no captured channel → no invented one
});

// A Contact page: page_type Utility, standard_type contact, hero + intro drafted.
function blockContactPage(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::Contact->value,
        'slug' => 'contact',
        'title' => 'Contact',
        'slot_payload' => ['hero_headline' => 'Let’s talk about your project', 'intro' => 'Reach us any way you like.'],
    ]);
}

it('composes the Contact page — real NAP (phone, email, address, hours) from §1, per-field gated', function () {
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    Location::factory()->create([
        'site_id' => $site->id,
        'email' => 'hello@sewergurus.com',
        'address' => '12 Main Street, Newark, NJ',
        'phone' => null,
        'hours' => ['mon' => ['open' => '8:00', 'close' => '17:00'], 'sat' => 'closed', 'sun' => 'closed'],
    ]);

    $page = blockContactPage($site);
    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()->not->toBeEmpty()
        ->and($markup)
        ->toContain('Let’s talk about your project')     // hero (drafted headline)
        ->toContain('lp-contact')                         // the NAP section
        ->toContain('href="tel:9735550100"')              // click-to-call (site phone)
        ->toContain('(973) 555-0100')
        ->toContain('href="mailto:hello@sewergurus.com"') // mailto
        ->toContain('12 Main Street, Newark, NJ')         // address, verbatim
        ->toContain('Hours')->toContain('Mon')->toContain('8:00 – 17:00') // an open day
        ->not->toContain('>Sun<')                         // closed days drop — no wall of "Closed"
        ->toContain('Prefer to just ask?')                // the soft closing CTA
        ->not->toContain('lp-cta--bold');                 // thin page → no pushy accent band
});

it('Contact: preview shows example details; publish omits the NAP when nothing is captured', function () {
    $site = Site::factory()->create(['phone' => null]); // no phone, no location
    $page = blockContactPage($site);

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)->toContain('lp-contact')->toContain('appears when you add your contact details');

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-contact-grid')               // the NAP block omits on publish
        ->toContain('Let’s talk about your project')      // hero + CTA still render
        ->toContain('Prefer to just ask?');
});

it('Contact: the lead form is a preview-only placeholder — never on the published page (delivery undecided)', function () {
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    $page = blockContactPage($site);

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-formsection')                                  // the placeholder section
        ->toContain('appears once your contact form delivery is set up')
        ->toContain('lp-formskel');                                    // the sketch

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-formsection')                             // a non-functional form never publishes
        ->not->toContain('lp-formskel')
        ->toContain('href="tel:9735550100"');                          // the real NAP still carries the page
});

/* ------------------------------------------------------------------ *
 * Service page — the Elementor→blocks migration's first non-standard type.
 * ------------------------------------------------------------------ */
function blockServiceDrafted(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'slug' => 'drain-cleaning',
        'title' => 'Drain Cleaning',
        'slot_payload' => [
            'service_area' => 'Drain Services · Northern NJ',
            'hero_problem' => 'A clogged drain is backing up your whole day.',
            'hero_solution' => 'We clear it fast and keep it clear — same-day service, no mess left behind.',
            'problem_explainer' => 'A slow or blocked drain rarely fixes itself, and the longer it sits the worse the backup gets.',
            'solution_overview' => '<p>Our technicians camera-inspect the line, clear the blockage at its source, and confirm free flow before we leave.</p>',
            'service_features' => ['Hydro-jetting for stubborn clogs', 'Camera line inspection', 'Root intrusion removal', 'Preventive maintenance plans'],
            'why_us' => 'Every technician is licensed and the work is backed by our written guarantee.',
            'faq' => [
                ['question' => 'How fast can you come out?', 'answer' => 'Usually same day for urgent backups.'],
                ['question' => 'Do you offer free estimates?', 'answer' => 'Yes — every quote is free and up front.'],
            ],
        ],
    ]);
}

it('composes a Service page from real inputs — problem H1, features checklist, two CTAs, real NAP', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(973) 555-0100', 'email' => 'help@sewergurus.com', 'address' => '10 Main St, Newark NJ']);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => '25-year warranty'], 'is_substantiated' => true,
    ]);
    $page = blockServiceDrafted($site);

    $markup = app(BlockContentAssembler::class)->compose(
        $page->fresh(),
        $page->slot_payload,
        ['hero_image' => ['url' => 'https://cdn.example/drain.webp', 'alt' => 'Clearing a drain']],
    );

    expect($markup)->toBeString()->not->toBeEmpty()
        // hero: the customer's problem is the H1, the solution is the subhead, the image renders
        ->toContain('A clogged drain is backing up your whole day.')
        ->toContain('We clear it fast and keep it clear')
        ->toContain('https://cdn.example/drain.webp')
        // the overview prose (both drafted body slots, HTML stripped to text)
        ->toContain('lp-prose')
        ->toContain('the longer it sits the worse the backup gets')
        ->toContain('camera-inspect the line')
        ->not->toContain('<p>Our technicians')                      // rich_text HTML stripped to plain text
        // features checklist
        ->toContain('lp-features-grid')
        ->toContain('Hydro-jetting for stubborn clogs')
        ->toContain('lp-feature')
        // grounded why-us prose (has substantiated proof)
        ->toContain('backed by our written guarantee')
        // FAQ accordion (drafted pairs)
        ->toContain('lp-faq-list')
        ->toContain('How fast can you come out?')
        // real NAP + both CTAs
        ->toContain('href="tel:9735550100"')
        ->toContain('help@sewergurus.com')
        ->toContain('lp-cta lp-cta--bold')                          // pushy CTA
        ->toContain('Ready to get it fixed?')
        ->toContain('Have a question first?');                      // soft closing CTA
});

it('Service page data-gates on publish: grounded why-us, testimonials, and NAP omit when empty', function () {
    $site = Site::factory()->create(['phone' => null]); // no phone, no location, no proof
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'title' => 'Drain Cleaning',
        'slot_payload' => [
            'hero_problem' => 'A clogged drain is backing up your whole day.',
            'hero_solution' => 'We clear it fast.',
            'service_features' => ['Hydro-jetting', 'Camera inspection'],
        ],
    ]);

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->toContain('A clogged drain is backing up your whole day.')  // hero still renders
        ->toContain('lp-features-grid')                               // features present
        ->not->toContain('lp-testimonials')                          // no reviews → omitted
        ->not->toContain('lp-contact-grid')                          // no NAP → omitted
        ->not->toContain('lp-placeholder');                          // never a placeholder on publish

    $preview = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, [], preview: true);
    expect($preview)
        ->toContain('lp-testimonials')                               // preview shows every section...
        ->toContain('lp-contact')
        ->toContain('lp-placeholder');                               // ...clearly marked as example
});

it('Service page: the blob ships block post_content and empties elementor_data', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(973) 555-0100']);
    $page = blockServiceDrafted($site);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());

    expect($blob['post_content'])->toBeString()->toContain('lp-features-grid')
        ->and($blob['elementor_data'])->toBe([]);                    // block body ships → elementor short-circuits
});

it('FAQ hero honesty: with no drafted Q&A the hero never promises answers to scroll to', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'phone' => '(973) 555-0100']);
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::Faq->value, 'slug' => 'faq', 'title' => 'FAQ',
        'slot_payload' => [
            'hero_headline' => 'Plumbing questions, answered',
            'intro' => 'Scroll through to find what you need.', // the drafted invitation
            // no faq slot — the accordion data-gates out
        ],
    ]);

    $publish = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($publish)
        ->not->toContain('lp-faq-list')                                       // no accordion...
        ->not->toContain('Scroll through to find what you need.')             // ...so no scroll promise
        ->toContain('Have a question? Get in touch and we’ll give you a straight answer.');

    // With drafted Q&A the invitation is honest and renders as drafted.
    $page->forceFill(['slot_payload' => array_merge($page->slot_payload, [
        'faq' => [['question' => 'How fast can you come out?', 'answer' => 'Usually same day.']],
    ])])->save();
    $withFaqs = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($withFaqs)
        ->toContain('lp-faq-list')
        ->toContain('Scroll through to find what you need.');
});

it('every page ships its FINER identity in the blob — rich standard pages never share the utility bucket', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $mk = fn (StandardPageType $type, string $slug) => Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility,
        'standard_type' => $type->value, 'slug' => $slug, 'title' => ucfirst($slug),
    ]);

    // The full standard-page map — each sends its OWN type, so lp-page-type-{type} can target it.
    foreach ([
        [StandardPageType::About, 'about'],
        [StandardPageType::Faq, 'faq'],
        [StandardPageType::Contact, 'contact'],
        [StandardPageType::AreasWeServe, 'areas-we-serve'],
        [StandardPageType::WhyChooseUs, 'why-choose-us'],
        [StandardPageType::Privacy, 'privacy'],   // true boilerplate keeps its own fine type too
        [StandardPageType::Terms, 'terms'],
    ] as [$type, $slug]) {
        $blob = app(MetaBlobAssembler::class)->assemble($mk($type, $slug)->fresh(), collect());
        expect($blob['page_type'])->toBe($type->value);
    }

    // Non-standard pages are unchanged: their page_type IS the fine identity.
    $service = blockServicePage($site, 'Drain Cleaning', 'drain-cleaning', 'x');
    expect(app(MetaBlobAssembler::class)->assemble($service->fresh(), collect())['page_type'])->toBe('service');
});

it('About story renders as a SPLIT beside the photo rail when a story image exists', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create(['site_id' => $site->id, 'story' => 'We started with one truck.']);
    $page = blockAboutPage($site);

    $markup = app(BlockContentAssembler::class)->compose(
        $page->fresh(), $page->slot_payload,
        ['story_image' => ['url' => 'https://cdn.example/team.webp', 'alt' => 'The crew on site']],
    );

    expect($markup)
        ->toContain('lp-story--split')                    // the split skin
        ->toContain('lp-story-rail')                      // the photo rail
        ->toContain('https://cdn.example/team.webp')
        ->toContain('The crew on site')                   // alt carried
        ->toContain('We started with one truck.')         // prose beside it
        ->not->toContain('AI stand-in photos');           // the recommendation note NEVER ships to a visitor

    // Without a story image the section keeps its centered single-column layout.
    $plain = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($plain)->toContain('lp-story')->not->toContain('lp-story--split')->not->toContain('lp-story-rail');
});

it('About story preview marks AI stand-ins loudly — real team photos are highly recommended', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    SiteNarrative::factory()->create(['site_id' => $site->id, 'story' => 'We started with one truck.']);
    $page = blockAboutPage($site);

    $preview = app(BlockContentAssembler::class)->compose(
        $page->fresh(), $page->slot_payload,
        ['story_image' => ['url' => 'https://cdn.example/ai-team.webp', 'alt' => 'Team']],
        preview: true,
    );

    expect($preview)
        ->toContain('lp-story--split')
        ->toContain('REAL photos of your team and your work are highly recommended');
});
