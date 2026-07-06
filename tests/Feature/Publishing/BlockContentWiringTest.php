<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Models\Content;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Site;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;

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

it('returns null for a page type whose block pattern has not shipped (falls back to existing render)', function () {
    $site = Site::factory()->create();
    $service = blockServicePage($site, 'Drain Cleaning', 'drain-cleaning', 'x');

    expect(app(BlockContentAssembler::class)->compose($service->fresh(), [], []))->toBeNull();
});

it('the meta-blob carries post_content for Home', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $home = blockHomePage($site);

    $blob = app(MetaBlobAssembler::class)->assemble($home->fresh(), collect());

    expect($blob)->toHaveKey('post_content')
        ->and($blob['post_content'])->toContain('Stop sewer problems');
});
