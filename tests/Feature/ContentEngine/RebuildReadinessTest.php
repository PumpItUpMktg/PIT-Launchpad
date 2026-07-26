<?php

use App\ContentEngine\Reconcile\ReadinessStatus;
use App\ContentEngine\Reconcile\RebuildReadiness;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;

/** @return array<string, array<string, mixed>> keyed rows */
function readinessRows(Site $site): array
{
    $rows = [];
    foreach (app(RebuildReadiness::class)->for($site) as $row) {
        $rows[$row->key] = $row->toArray();
    }

    return $rows;
}

it('reports an unbuilt site as red across structure and territory', function () {
    $site = Site::factory()->create();

    $rows = readinessRows($site);

    expect($rows['structure']['status'])->toBe(ReadinessStatus::Bad->value)
        ->and($rows['territory']['status'])->toBe(ReadinessStatus::Bad->value)
        // No silos yet → keywords row is a no-op green, not a false alarm.
        ->and($rows['keywords']['status'])->toBe(ReadinessStatus::Ok->value)
        ->and(app(RebuildReadiness::class)->hasWork($site))->toBeTrue();
});

it('flags each drifted stage from persisted rows only', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford', 'page_selected' => true]);

    // A silo with no WP category → categories amber.
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer', 'wp_category_id' => null]);

    // An unbucketed keyword → keywords amber.
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sewer repair']);

    // A service page pinned to no silo → pages amber.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => 'Sewer Repair', 'slug' => 'sewer-repair', 'silo_id' => null,
    ]);

    // A published post on no silo → blog routing red AND publish amber (it is live + Uncategorized).
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'A sewer story', 'silo_id' => null,
    ]);

    $rows = readinessRows($site);

    expect($rows['structure']['status'])->toBe(ReadinessStatus::Ok->value)      // 1 silo exists
        ->and($rows['territory']['status'])->toBe(ReadinessStatus::Ok->value)
        ->and($rows['keywords']['status'])->toBe(ReadinessStatus::Warn->value)
        ->and($rows['pages']['status'])->toBe(ReadinessStatus::Warn->value)
        ->and($rows['categories']['status'])->toBe(ReadinessStatus::Warn->value)
        ->and($rows['blog_routing']['status'])->toBe(ReadinessStatus::Bad->value)
        ->and($rows['publish']['status'])->toBe(ReadinessStatus::Warn->value)
        ->and($rows['blog_routing']['fix'])->toBe('re-route');
});

it('reports a fully-aligned site as all green with no work', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford', 'page_selected' => true]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer', 'wp_category_id' => 42]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sewer repair']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => 'Sewer Repair', 'slug' => 'sewer-repair', 'silo_id' => $silo->id,
    ]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'A sewer story', 'silo_id' => $silo->id,
    ]);

    $rows = readinessRows($site);

    foreach ($rows as $row) {
        expect($row['status'])->toBe(ReadinessStatus::Ok->value);
    }
    expect(app(RebuildReadiness::class)->hasWork($site))->toBeFalse();
});
