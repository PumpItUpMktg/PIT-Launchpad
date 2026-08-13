<?php

use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\ContentKind;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Keyword;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\Schema\ServiceSchemaBuilder;
use Database\Seeders\WireframeKitSeeder;

function refSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://referrer.example', 'brand_name' => 'Storm Ready NJ']);
}

function refService(Site $site, Silo $silo, array $overrides = []): Service
{
    $service = Service::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => 'Sump Pump Installation',
        'symptoms' => ['Water pooling around the basement floor'],
        'scope_items' => ['Right-sized pump selection'],
        'process_steps' => ['Assess the basement', 'Install and test'],
        'cost_factors' => ['Pit condition', 'Pump capacity', 'Discharge routing'],
        'price_range' => ['low' => 1200, 'high' => 3400, 'unit' => 'per install'],
        'warranty_applicable' => true,
        'silo_role' => ServiceSiloRole::Pillar,
    ], $overrides));
    $service->silos()->attach($silo->id);

    return $service;
}

function refSpokePage(Site $site, Silo $silo, Service $service, array $overrides = []): Content
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', 'service')->whereNull('site_id')->orderByDesc('version')->firstOrFail();
    $keyword = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump installation', 'source' => KeywordSource::Seed, 'status' => 'candidate']);

    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'primary_service_id' => $service->id,
        'target_keyword_id' => $keyword->id,
        'title' => 'Sump Pump Installation',
        'slug' => 'sump-pump-installation',
        'wireframe_kit_id' => $kit->id,
        'schema_type' => 'Service', // a stale stored value — the referral path must NOT return it
        'schema_payload' => ['@type' => 'Service', 'name' => 'Sump Pump Installation'],
        'slot_payload' => [
            'svc_intro' => 'We size, install, and test sump systems that hold through the worst spring surge.',
            'faq' => [['question' => 'How long?', 'answer' => 'Usually one visit.']],
        ],
    ], $overrides));
}

// ── Rendering ───────────────────────────────────────────────────────────────

it('referral: suppresses the price range, keeps cost factors, swaps to the configured referral CTA', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $service = refService($site, $silo, ['referral_mode' => true]);
    ConversionConfig::factory()->create(['site_id' => $site->id, 'referral_cta_label' => 'Find a provider', 'referral_cta_url' => 'https://directory.example/match']);
    $page = refSpokePage($site, $silo, $service);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('Pump capacity')                 // cost FACTORS still render (legitimate education)
        ->not->toContain('Typical range')            // the price range is suppressed
        ->not->toContain('$1,200')
        ->not->toContain('Get a free quote')         // the quote CTA is gone
        ->toContain('Find a provider')               // the tenant's referral label
        ->toContain('https://directory.example/match'); // the tenant's referral destination
});

it('referral: falls back to the contact CTA (never a dead link) when no referral CTA is configured', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $page = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => true]));

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->not->toContain('Get a free quote')
        ->toContain('Contact us')                    // fallback label — never a quoting claim
        ->toContain('#contact');                     // fallback target — never a dead link
});

it('normal service page is UNCHANGED (regression): honest range + quote CTA', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $page = refSpokePage($site, $silo, refService($site, $silo)); // referral_mode false by default

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)
        ->toContain('Typical range: $1,200–$3,400 per install')
        ->toContain('Get a free quote');
});

// ── Schema ──────────────────────────────────────────────────────────────────

it('referral: omits the Service schema node (keeps it for a normal page)', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $referral = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => true]));
    $normal = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => false, 'name' => 'Drain Cleaning']), ['slug' => 'drain-cleaning', 'title' => 'Drain Cleaning']);

    $refBlob = app(MetaBlobAssembler::class)->assemble($referral->fresh(), collect());
    $normBlob = app(MetaBlobAssembler::class)->assemble($normal->fresh(), collect());

    // Referral: no Service node at all (not even the stale stored 'Service'). The null schema keys are
    // filtered out of the seo blob entirely. WebPage / BreadcrumbList / Organization are plugin-side.
    expect($refBlob['seo'])->not->toHaveKey('schema_type')
        ->and($refBlob['seo'])->not->toHaveKey('schema_payload')
        // Normal: the live-composed Service node stays, provider = the Organization.
        ->and($normBlob['seo']['schema_type'])->toBe('Service')
        ->and($normBlob['seo']['schema_payload']['provider']['@type'])->toBe('Organization');
});

it('isReferral reflects the subject service', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $referral = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => true]));
    $normal = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => false, 'name' => 'Drain']), ['slug' => 'drain', 'title' => 'Drain']);

    $builder = app(ServiceSchemaBuilder::class);
    expect($builder->isReferral($referral->fresh(), $site))->toBeTrue()
        ->and($builder->isReferral($normal->fresh(), $site))->toBeFalse();
});

// ── Drafting ─────────────────────────────────────────────────────────────────

it('grounds a referral page and instructs the drafter with connector framing (no first-person / pricing / warranty)', function () {
    $site = refSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $page = refSpokePage($site, $silo, refService($site, $silo, ['referral_mode' => true]));

    $grounding = app(PageGroundingAssembler::class)->assemble($page->fresh());
    expect($grounding->referralMode)->toBeTrue();

    // preview() returns the exact system+prompt without a model call — assert the referral framing.
    $prompt = app(PageDrafter::class)->preview($grounding)['prompt'];

    expect($prompt)
        ->toContain('REFERRAL PAGE')
        ->toContain('connect you with a licensed provider')
        ->toContain('NO pricing')
        ->toContain('NEVER a first-person claim of doing the work');
});
