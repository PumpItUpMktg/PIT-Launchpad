<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Models\Content;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Silo;
use App\Publishing\MetaBlobAssembler;
use Illuminate\Support\Collection;
use Tests\Support\PublishHarness;

/** Recursively collect every node's widgetType/title/classes for assertions. */
function walkNodes(array $tree, callable $fn): void
{
    foreach ($tree as $node) {
        $fn($node);
        if (! empty($node['elements'])) {
            walkNodes($node['elements'], $fn);
        }
    }
}

function childService(string $siteId, string $siloId, string $title, string $slug, string $blurb): Content
{
    return Content::factory()->create([
        'site_id' => $siteId,
        'silo_id' => $siloId,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'status' => ContentStatus::Approved,
        'title' => $title,
        'slug' => $slug,
        'meta' => ['seo' => ['meta_description' => $blurb]],
    ]);
}

test('a hub page ships the BLOCK body with a services-grid of its silo children (elementor short-circuits)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);

    // The silo's child service pages → the grid cards (resolved at compose time, not drafted).
    childService($site->id, $silo->id, 'Hydro Jetting', 'hydro-jetting', 'High-pressure drain clearing.');
    childService($site->id, $silo->id, 'Rooter Service', 'rooter-service', 'Cable-clears stubborn clogs.');

    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => 'Warrantied work'], 'is_substantiated' => true,
    ]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '512-555-0100']);

    $hub = PublishHarness::approvedHubPage($site, $silo->id);

    $payload = app(MetaBlobAssembler::class)->assemble($hub->fresh(), new Collection);

    // Hub+spoke relay: the hub composes core blocks — post_content ships, elementor_data empties.
    expect($payload['page_type'])->toBe('hub')
        ->and($payload['kit'])->toBe('hub-page')
        ->and($payload['elementor_data'])->toBe([])
        ->and($payload['post_content'])->toBeString()
        // v1 hero_problem still feeds the hero (fallback), the drafted intro renders as prose
        ->toContain('Slow or clogged drains across your home?')
        ->toContain('full range of drain work')
        // the internal-link spine: one card per child spoke with its REAL permalink
        ->toContain('Hydro Jetting')
        ->toContain('Rooter Service')
        ->toContain('href="https://apex.example/hydro-jetting"')
        ->toContain('href="https://apex.example/rooter-service"');

    // The resolved sibling_services still travel in slot_payload (the plugin's slot reference).
    expect($payload['slot_payload']['sibling_services'])->toHaveCount(2)
        ->and($payload['slot_payload']['sibling_services'][0]['title'])->toBe('Hydro Jetting');
});

test('a hub page with no child services drops the services-grid section (no placeholder cards)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $hub = PublishHarness::approvedHubPage($site, $silo->id);
    $payload = app(MetaBlobAssembler::class)->assemble($hub->fresh(), new Collection);

    // Hero still renders; the empty services grid self-prunes — no headers over nothing.
    expect($payload['post_content'])->toBeString()
        ->toContain('Slow or clogged drains across your home?')
        ->not->toContain('lp-services-grid')
        ->and($payload['slot_payload'])->not->toHaveKey('sibling_services');
});
