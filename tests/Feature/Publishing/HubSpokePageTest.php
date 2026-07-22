<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\ContentKind;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Local\Proof\LocalReview;
use App\Local\Proof\ServiceReviewProvider;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\Schema\ServiceSchemaBuilder;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\FakeClaudeClient;

function hsSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://sewergurus.com', 'brand_name' => 'Sewer Gurus']);
}

/** An enriched §1 Service — the spoke page's full record contract. */
function hsService(Site $site, Silo $silo, array $overrides = []): Service
{
    $service = Service::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => 'Sump Pump Installation',
        'short_description' => 'Reliable sump systems, sized to your basement.',
        'symptoms' => ['Water pooling around the basement floor', 'A sump pit that never runs', 'Musty smells after rain'],
        'scope_items' => ['Right-sized pump selection', 'New basin and check valve', 'Full test under load'],
        'process_steps' => ['Assess the basement', 'Quote the exact system', 'Install and test same day'],
        'cost_factors' => ['Pit condition', 'Pump capacity', 'Discharge routing'],
        'price_range' => ['low' => 1200, 'high' => 3400, 'unit' => 'per install'],
        'warranty_applicable' => true,
    ], $overrides));
    $service->silos()->attach($silo->id);

    return $service;
}

function hsKeyword(Site $site, string $query): Keyword
{
    return Keyword::create([
        'site_id' => $site->id, 'query' => $query, 'source' => KeywordSource::Seed, 'status' => 'candidate',
    ]);
}

function hsSpokePage(Site $site, Silo $silo, Service $service, array $overrides = []): Content
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', 'service')->whereNull('site_id')->orderByDesc('version')->firstOrFail();

    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'primary_service_id' => $service->id,
        'target_keyword_id' => hsKeyword($site, 'sump pump installation')->id,
        'title' => 'Sump Pump Installation',
        'slug' => 'sump-pump-installation',
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => [
            'svc_intro' => 'A wet basement starts with a pump that cannot keep up. We size, install, and test sump systems that hold through the worst spring surge.',
            'faq' => [['question' => 'How long does install take?', 'answer' => 'Usually one visit.']],
        ],
    ], $overrides));
}

function hsHubPage(Site $site, Silo $silo, array $overrides = []): Content
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', 'hub')->whereNull('site_id')->orderByDesc('version')->firstOrFail();

    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Hub,
        'target_keyword_id' => hsKeyword($site, 'sump pump services')->id,
        'title' => 'Sump Pump Services',
        'slug' => 'sump-pump-services',
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => [
            'hub_intro' => 'From new installations to battery backups, this is the full range of sump pump work we handle — sized to the basement, tested under load, and backed in writing.',
            'faq' => [['question' => 'Which pump do I need?', 'answer' => 'Depends on the pit and inflow — we assess first.']],
        ],
    ], $overrides));
}

it('composes the spoke: keyword H1, symptoms, scope, record process, cost with the honest range, related spine', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service);

    // The silo spine: the hub + a sibling spoke (both materialized).
    hsHubPage($site, $silo);
    Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Service, 'title' => 'Battery Backup Installation', 'slug' => 'battery-backup-installation',
    ]);
    // A page in ANOTHER silo must never appear in the related spine.
    $otherSilo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);
    Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $otherSilo->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Service, 'title' => 'Hydro Jetting', 'slug' => 'hydro-jetting',
    ]);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()
        // H1 = the primary keyword (Option A carry-over), capitalized — no drafted headline present.
        ->toContain('Sump pump installation')
        // Symptoms — the record bullets under the alert marker.
        ->toContain('lp-symptoms')
        ->toContain('Water pooling around the basement floor')
        // Scope — the record's checked list.
        ->toContain('lp-features-grid')
        ->toContain('New basin and check valve')
        // Process — the record's own ordered steps.
        ->toContain('Assess the basement')
        // Cost — the honest range line + factors.
        ->toContain('lp-cost')
        ->toContain('Typical range: $1,200–$3,400 per install')
        ->toContain('Pump capacity')
        // Related spine: hub + sibling, never cross-silo.
        ->toContain('lp-related')
        ->toContain('href="https://sewergurus.com/sump-pump-services"')
        ->toContain('href="https://sewergurus.com/battery-backup-installation"')
        ->not->toContain('hydro-jetting')
        // Gated reviews/jobs stay out with the null providers.
        ->not->toContain('lp-testimonials')
        ->not->toContain('lp-jobs');
});

it('a configured site lead form makes the service-description row a 60/40 two-column with [lp_form]', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service);

    // Site-wide form embed configured (the Connections & Feeds setup input).
    ConversionConfig::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'form_embed' => '<iframe src="https://api.leadconnectorhq.com/widget/form/xyz"></iframe>',
    ]);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    // The description row is now the 60/40 form layout carrying the plugin's [lp_form] shortcode.
    expect($markup)->toContain('lp-prose-form')
        ->toContain('[lp_form]')
        ->toContain('flex-basis:60%')
        ->toContain('flex-basis:40%')
        ->toContain('What this service covers');

    // The [lp_form] shortcode renders the blob's form_embed — which falls back to the site embed,
    // so the shortcode isn't empty. The iframe never rides post_content (kses strips it).
    expect($markup)->not->toContain('leadconnectorhq');
    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());
    expect($blob['form_embed'])->toBe('<iframe src="https://api.leadconnectorhq.com/widget/form/xyz"></iframe>');
});

it('with NO lead form the description row stays single-column prose (no [lp_form])', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('What this service covers')
        ->not->toContain('lp-prose-form')
        ->not->toContain('[lp_form]');
});

it('renders FAQ answer inline HTML (internal links) instead of shipping escaped raw tags', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service, [
        'slot_payload' => [
            'svc_intro' => 'We size, install, and test sump systems that hold through the worst spring surge.',
            'faq' => [[
                'question' => 'How much does it cost?',
                'answer' => 'It depends — start with our <a href="/basement-waterproofing-cost-guide">Cost Guide</a>. <script>alert(1)</script>',
            ]],
        ],
    ]);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('<a href="/basement-waterproofing-cost-guide">Cost Guide</a>') // the link renders
        ->not->toContain('&lt;a href')                                             // NOT escaped raw tags
        ->not->toContain('<script>');                                              // sanitized away
});

it('the cost section renders factors-only when the record has no range — never a blank price line', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = hsService($site, $silo, ['price_range' => null]);
    $page = hsSpokePage($site, $silo, $service);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('lp-cost')
        ->toContain('Pit condition')
        ->not->toContain('Typical range:')
        ->not->toContain('lp-cost-range');
});

it('the comparison renders only when the owner enables it — verbatim points, off by default', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $comparison = [
        'enabled' => true,
        'title' => 'Pedestal vs. submersible',
        'option_a' => ['name' => 'Pedestal', 'points' => ['Motor sits above the pit', 'Easier to service']],
        'option_b' => ['name' => 'Submersible', 'points' => ['Quieter under the floor', 'Handles higher volume']],
        'verdict' => 'Most finished basements are better served by a submersible.',
    ];
    $service = hsService($site, $silo, ['comparison' => $comparison]);
    $page = hsSpokePage($site, $silo, $service);

    $on = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($on)->toContain('lp-compare')
        ->toContain('Pedestal vs. submersible')
        ->toContain('Motor sits above the pit')
        ->toContain('Handles higher volume')
        ->toContain('Most finished basements are better served by a submersible.');

    $service->forceFill(['comparison' => array_merge($comparison, ['enabled' => false])])->save();
    $off = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);
    expect($off)->not->toContain('lp-compare');
});

it('provider-fed service reviews render on the spoke the moment a provider binds', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service);

    app()->instance(ServiceReviewProvider::class, new class implements ServiceReviewProvider
    {
        public function for(Service $service): array
        {
            return [new LocalReview('Dan', 5, 'The new pump ran through a week of storms without a hiccup.', 'Norristown', 'Sump Pump Installation')];
        }
    });

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('lp-testimonials')
        ->toContain('The new pump ran through a week of storms without a hiccup.');
});

it('composes the hub: category keyword H1, one grid card per spoke, refreshed on recompose', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    hsSpokePage($site, $silo, $service);
    $hub = hsHubPage($site, $silo);

    $markup = app(BlockContentAssembler::class)->compose($hub->fresh(), $hub->slot_payload, []);

    expect($markup)->toBeString()
        ->toContain('Sump pump services')                                   // category keyword H1
        ->toContain('full range of sump pump work')                         // drafted intro
        ->toContain('lp-services-grid')
        ->toContain('Sump Pump Installation')                               // one card per child spoke
        ->toContain('Reliable sump systems, sized to your basement.')       // the record's short_description
        ->toContain('href="https://sewergurus.com/sump-pump-installation"')
        ->not->toContain('lp-testimonials');                                // gated reviews absent

    // Data-bound at compose time: a spoke added later appears on the next compose — no regeneration.
    Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Service, 'title' => 'Battery Backup Installation', 'slug' => 'battery-backup-installation',
    ]);
    $recomposed = app(BlockContentAssembler::class)->compose($hub->fresh(), $hub->slot_payload, []);
    expect($recomposed)->toContain('href="https://sewergurus.com/battery-backup-installation"');
});

it('builds the spoke Service node — serviceType from the primary keyword, offers only from a real range', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service);

    $node = app(ServiceSchemaBuilder::class)->build(
        $page->fresh(), $site, 'https://sewergurus.com/', 'https://sewergurus.com/sump-pump-installation',
    );

    expect($node['serviceType'])->toBe('sump pump installation')            // the keyword, not the name
        ->and($node['provider']['@id'])->toBe('https://sewergurus.com/#org')
        ->and($node['offers']['priceSpecification']['minPrice'])->toEqual(1200.0)
        ->and($node['offers']['priceSpecification']['maxPrice'])->toEqual(3400.0);
    expect($node)->not->toHaveKeys(['review', 'aggregateRating']);

    // No range ⇒ no offers node at all — never a blank or invented price.
    $service->forceFill(['price_range' => null])->save();
    $bare = app(ServiceSchemaBuilder::class)->build($page->fresh(), $site, 'https://sewergurus.com/', null);
    expect($bare)->not->toHaveKey('offers');
});

it('builds the hub Service node with a hasOfferCatalog of its spokes', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $service = hsService($site, $silo);
    hsSpokePage($site, $silo, $service);
    $hub = hsHubPage($site, $silo);

    $node = app(ServiceSchemaBuilder::class)->buildForHub(
        $hub->fresh(), $site, 'https://sewergurus.com/', 'https://sewergurus.com/sump-pump-services',
        [['name' => 'Sump Pump Installation', 'url' => 'https://sewergurus.com/sump-pump-installation']],
    );

    expect($node['serviceType'])->toBe('sump pump services')
        ->and($node['hasOfferCatalog']['@type'])->toBe('OfferCatalog')
        ->and($node['hasOfferCatalog']['itemListElement'][0]['item']['name'])->toBe('Sump Pump Installation')
        ->and($node['hasOfferCatalog']['itemListElement'][0]['item']['url'])->toBe('https://sewergurus.com/sump-pump-installation');
});

it('keeps the spoke drafter geo-neutral: enrichment in, markets out', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'region' => 'PA']);
    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service, ['slot_payload' => []]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page->fresh());

    expect($grounding->markets)->toBe([])                                    // NO locality reaches the prompt
        ->and($grounding->services[0]['symptoms'])->toContain('Musty smells after rain')
        ->and($grounding->services[0]['cost_factors'])->toContain('Discharge routing')
        ->and($grounding->services[0]['price_range']['low'])->toEqual(1200);

    $prompt = (new PageDrafter(new DraftCall(new FakeClaudeClient(''))))->preview($grounding)['prompt'];

    expect($prompt)->toContain('keep the copy geo-neutral; do not name any town')
        ->not->toContain('Trooper');
});

it('the enrichment record round-trips: repeaters, price range, and the comparison block', function () {
    $site = hsSite();
    $service = Service::factory()->create([
        'site_id' => $site->id,
        'symptoms' => ['One', 'Two'],
        'scope_items' => ['Included A'],
        'cost_factors' => ['Factor A'],
        'price_range' => ['low' => 900, 'high' => 2500, 'unit' => 'per job'],
        'comparison' => ['enabled' => true, 'title' => 'A vs B', 'option_a' => ['name' => 'A', 'points' => ['a1']], 'option_b' => ['name' => 'B', 'points' => ['b1']], 'verdict' => 'A.'],
        'warranty_applicable' => true,
    ]);

    $fresh = $service->fresh();
    expect($fresh->symptoms)->toBe(['One', 'Two'])
        ->and($fresh->price_range['high'])->toEqual(2500)
        ->and($fresh->comparison['option_b']['points'])->toBe(['b1'])
        ->and($fresh->warranty_applicable)->toBeTrue();
});

it('renders the drafted section H2 when present, and the static label only as fallback', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = hsService($site, $silo);

    $faq = ['faq' => [['question' => 'How long does install take?', 'answer' => 'Usually one visit.']]];

    // Drafted: the composer renders the drafted H2, never the static label.
    $drafted = hsSpokePage($site, $silo, $service, ['slot_payload' => $faq + [
        'symptoms_heading' => 'Telltale signs your sump pump is failing',
        'faq_heading' => 'Sump pump questions, answered honestly',
    ]]);
    $markup = app(BlockContentAssembler::class)->compose($drafted->fresh(), $drafted->slot_payload, []);
    expect($markup)->toContain('Telltale signs your sump pump is failing')
        ->toContain('Sump pump questions, answered honestly')
        ->not->toContain('Signs you need this')     // the static label is not used when drafted
        ->not->toContain('Common questions');

    // Undrafted: the static label is the honest fallback.
    $bare = hsSpokePage($site, $silo, $service, ['slug' => 'sump-pump-repair', 'slot_payload' => $faq]);
    $bareMarkup = app(BlockContentAssembler::class)->compose($bare->fresh(), $bare->slot_payload, []);
    expect($bareMarkup)->toContain('Signs you need this')
        ->toContain('Common questions');
});

it('drafted section H2s are unique across sibling spokes on a site', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $svcA = hsService($site, $silo, ['name' => 'Sump Pump Installation']);
    $svcB = hsService($site, $silo, ['name' => 'Battery Backup Installation']);

    $pageA = hsSpokePage($site, $silo, $svcA, ['slug' => 'sump-pump-installation', 'slot_payload' => [
        'symptoms_heading' => 'Signs your sump pump is on its way out',
        'scope_heading' => 'What a full sump pump install includes',
    ]]);
    $pageB = hsSpokePage($site, $silo, $svcB, ['slug' => 'battery-backup-installation', 'slot_payload' => [
        'symptoms_heading' => 'When your backup battery can no longer keep up',
        'scope_heading' => 'Everything a battery backup install covers',
    ]]);

    $markupA = app(BlockContentAssembler::class)->compose($pageA->fresh(), $pageA->slot_payload, []);
    $markupB = app(BlockContentAssembler::class)->compose($pageB->fresh(), $pageB->slot_payload, []);

    // Each page carries ITS drafted H2s and none of its sibling's — no two spokes share a drafted heading.
    expect($markupA)->toContain('Signs your sump pump is on its way out')
        ->not->toContain('When your backup battery can no longer keep up');
    expect($markupB)->toContain('When your backup battery can no longer keep up')
        ->not->toContain('Signs your sump pump is on its way out');
});

it('feeds sibling section H2s to the drafter so a new page keeps its headings distinct', function () {
    $site = hsSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    // An already-drafted sibling spoke in the same silo carries a distinctive H2.
    Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Service, 'title' => 'Battery Backup Installation', 'slug' => 'battery-backup-installation',
        'slot_payload' => ['symptoms_heading' => 'When your backup battery can no longer keep up'],
    ]);

    $service = hsService($site, $silo);
    $page = hsSpokePage($site, $silo, $service, ['slot_payload' => []]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page->fresh());
    $prompt = (new PageDrafter(new DraftCall(new FakeClaudeClient(''))))->preview($grounding)['prompt'];

    expect($grounding->siblingHeadings)->toContain('When your backup battery can no longer keep up')
        ->and($prompt)->toContain('HEADINGS ALREADY USED ON OTHER PAGES OF THIS SITE')
        ->and($prompt)->toContain('When your backup battery can no longer keep up');
});
