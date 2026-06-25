<?php

use App\Enums\ContentStatus;
use App\Guided\GrowDashboard;
use App\Models\Content;
use App\Models\Site;
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

it('gives every page row its morphing primary action, badge tone, and bulk lane', function () {
    $ctx = growSite();
    $planned = $ctx['base'];
    $review = growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::NeedsReview]);
    $approved = growPage($ctx, ['slot_payload' => ['hero' => 'x'], 'status' => ContentStatus::Approved]);
    $generating = growPage($ctx, ['slot_payload' => [], 'meta' => ['generating_at' => now()->toIso8601String()]]);
    $pending = growPage($ctx, ['slot_payload' => [], 'wireframe_kit_id' => null]); // no kit → composer pending

    $rows = collect(app(GrowDashboard::class)->pages($ctx['site']))->keyBy('id');

    expect($rows[$planned->id]['action'])->toBe('generate')
        ->and($rows[$planned->id]['tone'])->toBe('idle')
        ->and($rows[$planned->id]['bulk'])->toBeNull();
    expect($rows[$review->id]['action'])->toBe('review')
        ->and($rows[$review->id]['tone'])->toBe('warn')
        ->and($rows[$review->id]['bulk'])->toBe('approve');
    expect($rows[$approved->id]['action'])->toBe('publish')
        ->and($rows[$approved->id]['bulk'])->toBe('publish');
    expect($rows[$generating->id]['action'])->toBeNull()
        ->and($rows[$generating->id]['tone'])->toBe('info');
    expect($rows[$pending->id]['action'])->toBe('pending');

    // most-actionable first: review (approve) before the planned generate row
    $order = collect(app(GrowDashboard::class)->pages($ctx['site']))->pluck('id');
    expect($order->search($review->id))->toBeLessThan($order->search($planned->id));
});

it('carries the permalink on every row', function () {
    $ctx = growSite();
    growPage($ctx, ['slug' => 'services/tankless-install', 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::Approved]);

    $row = collect(app(GrowDashboard::class)->pages($ctx['site']))
        ->firstWhere('permalink', '/services/tankless-install');

    expect($row)->not->toBeNull();
});
