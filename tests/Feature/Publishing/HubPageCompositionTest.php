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

test('a hub page renders the service-hub LIBRARY body with a services-grid of its silo children', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);

    // The silo's child service pages → the grid cards (resolved, not drafted).
    childService($site->id, $silo->id, 'Hydro Jetting', 'hydro-jetting', 'High-pressure drain clearing.');
    childService($site->id, $silo->id, 'Rooter Service', 'rooter-service', 'Cable-clears stubborn clogs.');

    // Proof + phone so the conditional why_us / cta survive and resolve.
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => 'Warrantied work'], 'is_substantiated' => true,
    ]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '512-555-0100']);

    $hub = PublishHarness::approvedHubPage($site, $silo->id);

    $payload = app(MetaBlobAssembler::class)->assemble($hub->fresh(), new Collection);

    // Native body comes from the wireframe LIBRARY (wf-block-*), never the lp-zone fallback.
    expect($payload['page_type'])->toBe('hub')
        ->and($payload['kit'])->toBe('hub-page')
        ->and($payload['elementor_data'])->toBeArray()->not->toBeEmpty();

    $topClasses = array_map(fn ($b) => (string) ($b['settings']['_css_classes'] ?? ''), $payload['elementor_data']);
    expect(collect($topClasses)->contains(fn ($c) => str_contains($c, 'wf-block-hero')))->toBeTrue()
        ->and(collect($topClasses)->contains(fn ($c) => str_contains($c, 'wf-block-intro')))->toBeTrue()
        ->and(collect($topClasses)->contains(fn ($c) => str_contains($c, 'wf-block-services-grid')))->toBeTrue()
        ->and(collect($topClasses)->every(fn ($c) => ! str_contains($c, 'lp-zone')))->toBeTrue();

    // The hero headline + intro are injected from the drafted slots.
    $titles = [];
    $editors = [];
    walkNodes($payload['elementor_data'], function (array $n) use (&$titles, &$editors): void {
        if (($n['widgetType'] ?? null) === 'heading') {
            $titles[] = (string) ($n['settings']['title'] ?? '');
        }
        if (($n['widgetType'] ?? null) === 'text-editor') {
            $editors[] = (string) ($n['settings']['editor'] ?? '');
        }
    });

    expect($titles)->toContain('Slow or clogged drains across your home?')   // wf-hero-headline
        ->and($titles)->toContain('Hydro Jetting')                            // services-grid card 1 title
        ->and($titles)->toContain('Rooter Service');                          // card 2 title

    // Each grid card body carries a "Learn more" link to the child page slug.
    $joinedEditors = implode("\n", $editors);
    expect($joinedEditors)->toContain('href="https://apex.example/hydro-jetting"')
        ->and($joinedEditors)->toContain('href="https://apex.example/rooter-service"');

    // The resolved sibling_services travel in slot_payload too (source of truth alongside the body).
    expect($payload['slot_payload']['sibling_services'])->toHaveCount(2)
        ->and($payload['slot_payload']['sibling_services'][0]['title'])->toBe('Hydro Jetting');
});

test('a hub page with no child services drops the services-grid block (no placeholder cards)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $hub = PublishHarness::approvedHubPage($site, $silo->id);
    $payload = app(MetaBlobAssembler::class)->assemble($hub->fresh(), new Collection);

    $topClasses = array_map(fn ($b) => (string) ($b['settings']['_css_classes'] ?? ''), $payload['elementor_data']);

    // Hero still renders; the empty services-grid self-prunes.
    expect(collect($topClasses)->contains(fn ($c) => str_contains($c, 'wf-block-hero')))->toBeTrue()
        ->and(collect($topClasses)->contains(fn ($c) => str_contains($c, 'wf-block-services-grid')))->toBeFalse()
        ->and($payload['slot_payload'])->not->toHaveKey('sibling_services');
});
