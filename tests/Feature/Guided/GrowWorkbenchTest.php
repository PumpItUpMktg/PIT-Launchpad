<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Guided\GrowDashboard;
use App\Models\Content;
use App\Models\Market;
use App\Models\Site;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\PageFixture;

/**
 * A planned page (from PageFixture) plus siblings in each build-out state on the same site,
 * so the workbench read-model can be exercised end to end.
 *
 * @return array{site: Site, kit: string}
 */
function growSite(): array
{
    $base = PageFixture::intakePage(); // a planned, kit-bound service page

    return ['site' => $base->site, 'kit' => (string) $base->wireframe_kit_id, 'base' => $base];
}

function growPage(array $ctx, array $attrs): Content
{
    return Content::factory()->page()->create(array_merge([
        'site_id' => $ctx['site']->id,
        'wireframe_kit_id' => $ctx['kit'],
    ], $attrs));
}

it('derives the header counts from the same page set as the list (no drift)', function () {
    $ctx = growSite(); // 1 planned already

    growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::Published]);
    growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::Approved]);

    $stats = app(GrowDashboard::class)->stats($ctx['site']);
    $pages = app(GrowDashboard::class)->pages($ctx['site']);

    expect($stats)->toEqual(['live' => 1, 'building' => 1, 'planned' => 1])
        ->and($stats['live'] + $stats['building'] + $stats['planned'])->toBe(count($pages));
});

it('renders each row from the canonical vocabulary with its loop actions and bulk lane', function () {
    $ctx = growSite();
    $planned = $ctx['base'];
    $review = growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::NeedsReview]);
    $approved = growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::Approved]);
    $generating = growPage($ctx, ['slot_payload' => [], 'meta' => ['generating_at' => now()->toIso8601String()]]);
    $pending = growPage($ctx, ['slot_payload' => [], 'wireframe_kit_id' => null]); // no kit → held-composer

    $rows = collect(app(GrowDashboard::class)->pages($ctx['site']))->keyBy('id');

    expect($rows[$planned->id]['actions'])->toBe(['generate'])
        ->and($rows[$planned->id]['client_line'])->toBe('Ready to generate')
        ->and($rows[$planned->id]['whose_move'])->toBe('Your move — generate when ready.')
        ->and($rows[$planned->id]['tone'])->toBe('idle')
        ->and($rows[$planned->id]['bulk'])->toBeNull();
    // review rows offer BOTH Review and per-page Approve
    expect($rows[$review->id]['actions'])->toBe(['review', 'approve'])
        ->and($rows[$review->id]['client_line'])->toBe('Ready to review')
        ->and($rows[$review->id]['tone'])->toBe('warn')
        ->and($rows[$review->id]['bulk'])->toBe('approve');
    expect($rows[$approved->id]['actions'])->toBe(['publish'])
        ->and($rows[$approved->id]['client_line'])->toBe('Approved — ready to publish')
        ->and($rows[$approved->id]['bulk'])->toBe('publish');
    expect($rows[$generating->id]['actions'])->toBe([])
        ->and($rows[$generating->id]['client_line'])->toBe('Writing now')
        ->and($rows[$generating->id]['tone'])->toBe('info');
    // held-composer: no live action; the sacred line + operator-truth whose-move + diagnostic tail
    expect($rows[$pending->id]['actions'])->toBe([])
        ->and($rows[$pending->id]['client_line'])->toBe("We're still preparing this page")
        ->and($rows[$pending->id]['whose_move'])->toBe('Not available yet — pending the composer build.')
        ->and($rows[$pending->id]['operator_tail'])->toBe('composer pending');

    // most-actionable first: review before the planned generate row
    $order = collect(app(GrowDashboard::class)->pages($ctx['site']))->pluck('id');
    expect($order->search($review->id))->toBeLessThan($order->search($planned->id));
});

it('holds (no live action) a kit-bound page with no grounding — held-grounding vocabulary', function () {
    // a service page WITH a kit but on a site with no §1 Service → can't ground → no Generate
    $base = PageFixture::intakePage();
    $ungrounded = Content::factory()->page()->create([
        'site_id' => Site::factory()->create()->id, // a fresh site with zero services
        'wireframe_kit_id' => $base->wireframe_kit_id,
        'page_type' => PageType::Service,
        'slot_payload' => [],
    ]);

    $row = collect(app(GrowDashboard::class)->pages($ungrounded->site))->firstWhere('id', $ungrounded->id);

    expect($row['actions'])->toBe([])                    // never a live Generate
        ->and($row['client_line'])->toBe("We're still preparing this page")
        ->and($row['whose_move'])->toBe('Not available yet — pending Territory→Market.')
        ->and($row['operator_tail'])->toBe('grounding pending — Territory→§1 Market');
});

it('makes a town page generatable once it has a kit and a market', function () {
    $site = Site::factory()->create();
    Market::factory()->create(['site_id' => $site->id]);
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('page_type', 'location')->firstOrFail();
    $town = Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'wireframe_kit_id' => $kit->id, 'slot_payload' => [],
    ]);

    $row = collect(app(GrowDashboard::class)->pages($site))->firstWhere('id', $town->id);

    expect($row['actions'])->toBe(['generate'])
        ->and($row['client_line'])->toBe('Ready to generate');
});

it('groups the workbench into Core / Service / Town lanes with per-section counts', function () {
    $ctx = growSite(); // 1 planned service page already
    growPage($ctx, ['page_type' => PageType::Service, 'slot_payload' => []]);
    growPage($ctx, ['page_type' => PageType::Home, 'slot_payload' => []]);
    growPage($ctx, ['page_type' => PageType::Utility, 'slot_payload' => []]);
    growPage($ctx, ['page_type' => PageType::Location, 'slot_payload' => []]);

    $sections = collect(app(GrowDashboard::class)->sections($ctx['site']))->keyBy('key');

    // ordered Core → Service → Town
    expect(collect(app(GrowDashboard::class)->sections($ctx['site']))->pluck('key')->all())
        ->toBe(['core', 'service', 'town']);

    expect($sections['core']['label'])->toBe('Core pages')
        ->and($sections['core']['count'])->toBe(2)            // home + utility
        ->and($sections['service']['count'])->toBe(2)         // base + the extra service
        ->and($sections['town']['count'])->toBe(1)            // location
        ->and($sections['service']['count'])->toBe(count($sections['service']['pages']));

    // section pages carry the vocab row shape (and no internal sort/group keys leak)
    expect($sections['service']['pages'][0])->toHaveKeys(['id', 'title', 'permalink', 'client_line', 'whose_move', 'operator_tail', 'actions', 'bulk'])
        ->and($sections['service']['pages'][0])->not->toHaveKey('rank')
        ->and($sections['service']['pages'][0])->not->toHaveKey('section');
});

it('drops empty lanes — a site with only service pages shows just the Service section', function () {
    $ctx = growSite(); // 1 service page, no core/town

    $keys = collect(app(GrowDashboard::class)->sections($ctx['site']))->pluck('key');

    expect($keys->all())->toBe(['service']);
});

it('carries the permalink on every row', function () {
    $ctx = growSite();
    growPage($ctx, ['slug' => 'services/tankless-install', 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::Approved]);

    $row = collect(app(GrowDashboard::class)->pages($ctx['site']))
        ->firstWhere('permalink', '/services/tankless-install');

    expect($row)->not->toBeNull();
});
