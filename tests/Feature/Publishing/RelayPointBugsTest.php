<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\ServedTowns;
use App\Models\Content;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockBuilder;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;
use Database\Seeders\WireframeKitSeeder;

// ── Point bug 2: areaServed cleanup — junk never reaches served_towns / areaServed ──

test('served-town validation rejects numbered parse artifacts and bare numbers', function () {
    expect(ServedTowns::isValidEntry('Montclair, NJ'))->toBeTrue()
        ->and(ServedTowns::isValidEntry('Trooper'))->toBeTrue()
        ->and(ServedTowns::isValidEntry('Halls Cross Roads, MD'))->toBeTrue()
        // The SPG artifacts from the audit — numbered prefixes and bare numbers never reach schema.
        ->and(ServedTowns::isValidEntry('1, Abingdon'))->toBeFalse()
        ->and(ServedTowns::isValidEntry('2, Halls Cross Roads'))->toBeFalse()
        ->and(ServedTowns::isValidEntry('12'))->toBeFalse()
        ->and(ServedTowns::isValidEntry(''))->toBeFalse();
});

// ── Point bug 3: dead #contact anchor — the CTA group carries id="contact" ──

test('a group with a Gutenberg anchor attr emits a matching HTML id (never a dead in-page link)', function () {
    $b = new BlockBuilder;

    $anchored = $b->group([$b->paragraph('Reach us')], ['anchor' => 'contact']);
    expect($anchored)->toContain('<div id="contact"');

    // No anchor → no stray id attribute.
    $plain = $b->group([$b->paragraph('Reach us')]);
    expect($plain)->toContain('<div class="wp-block-group')
        ->not->toContain(' id="');
});

// ── A composed page: the soft-close CTA is the anchor target, and its button lands on it ──

function rpbPage(): Content
{
    (new WireframeKitSeeder)->run();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'SPG']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Basement Waterproofing']);
    $service = Service::factory()->create([
        'site_id' => $site->id,
        'name' => 'Basement Waterproofing',
        'symptoms' => ['Water pooling on the floor'],
        'scope_items' => ['Interior drain tile'],
        'process_steps' => ['Inspect', 'Excavate', 'Seal'],
        'cost_factors' => ['Foundation depth'],
    ]);
    $kit = WireframeKit::query()->where('page_type', 'service')->whereNull('site_id')->orderByDesc('version')->firstOrFail();

    return Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'primary_service_id' => $service->id,
        'title' => 'Basement Waterproofing',
        'slug' => 'basement-waterproofing',
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => ['svc_intro' => 'We keep basements dry with interior drainage sized to the water table.'],
    ]);
}

it('the composed page resolves every in-page #contact anchor to a real target', function () {
    $markup = app(BlockContentAssembler::class)->compose(($page = rpbPage())->fresh(), $page->slot_payload, []);

    // The CTA that #contact links point at exists as a real HTML id.
    expect($markup)->toContain('id="contact"');

    // Every in-page anchor the markup references resolves to an id on the same page — no dead #contact.
    preg_match_all('/href="#([\w-]+)"/', $markup, $hrefs);
    preg_match_all('/\bid="([\w-]+)"/', $markup, $ids);
    $targets = array_values(array_unique($ids[1]));
    $dangling = array_values(array_diff(array_unique($hrefs[1]), $targets));

    expect($hrefs[1])->not->toBeEmpty()      // the page really does link to #contact
        ->and($dangling)->toBe([]);          // and no in-page anchor is left without a target
});

// ── Point bug 6: heading hierarchy — section children are H3, never a skipped H4 ──

it('section children render as H3 (no heading level is skipped down to H4)', function () {
    $markup = app(BlockContentAssembler::class)->compose(($page = rpbPage())->fresh(), $page->slot_payload, []);

    expect($markup)->toContain('<h1')   // the page title
        ->toContain('<h3')              // section card sub-headings
        ->not->toContain('<h4');        // never the skipped level
});

// ── Point bug 4: unbalanced </p> — the paragraph wrapper never double-wraps or leaks a stray tag ──

test('the paragraph builder strips embedded/stray paragraph tags before wrapping', function () {
    $b = new BlockBuilder;

    // A drafted value that already carries a wrapping <p>…</p> → exactly one <p>, no nesting.
    $wrapped = $b->paragraph('<p>Already wrapped.</p>');
    expect(substr_count($wrapped, '<p>'))->toBe(1)
        ->and(substr_count($wrapped, '</p>'))->toBe(1)
        ->and($wrapped)->toContain('>Already wrapped.</p>');

    // A stray unbalanced </p> in the value → never a dangling close tag.
    $stray = $b->paragraph('The value table is here.</p>');
    expect(substr_count($stray, '</p>'))->toBe(1)
        ->and($stray)->toContain('>The value table is here.</p>');
});

it('the composed page is well-formed — every block-level tag balances', function () {
    $markup = app(BlockContentAssembler::class)->compose(($page = rpbPage())->fresh(), $page->slot_payload, []);

    // Strip WP block comments and self-closing tags, then confirm each block-level element balances.
    $html = (string) preg_replace('/<!--.*?-->/s', '', $markup);
    $html = (string) preg_replace('#<(img|br|hr|input)\b[^>]*/?>#i', '', $html);

    foreach (['p', 'div', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'figure', 'a', 'section'] as $tag) {
        $open = preg_match_all('#<'.$tag.'(?:\s[^>]*)?>#i', $html);
        $close = preg_match_all('#</'.$tag.'>#i', $html);
        expect($close)->toBe($open, "unbalanced <{$tag}> ({$open} open / {$close} close)");
    }
});

// ── Point bug 5: breadcrumb leaf = the page's short name, not the full SEO title ──

it('the breadcrumb leaf uses the page short name, not the SEO subtitle', function () {
    (new WireframeKitSeeder)->run();
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Basement Waterproofing']);
    $kit = WireframeKit::query()->where('page_type', 'hub')->whereNull('site_id')->orderByDesc('version')->firstOrFail();
    $page = Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Hub,
        'title' => 'Basement Waterproofing',
        'slug' => 'basement-waterproofing',
        'wireframe_kit_id' => $kit->id,
        'meta' => ['seo' => ['title' => 'Basement Waterproofing: Rules, Violations & Fixes']],
        'slot_payload' => ['hub_intro' => 'Everything we do to keep a basement dry.'],
    ]);

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());
    $leaf = end($blob['seo']['breadcrumbs']);

    expect($leaf['name'])->toBe('Basement Waterproofing')
        ->and($leaf['url'])->toBe('');
});
