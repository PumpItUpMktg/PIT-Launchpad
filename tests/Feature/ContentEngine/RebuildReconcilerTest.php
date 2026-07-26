<?php

use App\ContentEngine\Reconcile\RebuildReconciler;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Jobs\SyncSiloCategories;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function rrSilo(Site $site, string $name, array $include): Silo
{
    return Silo::factory()->create([
        'site_id' => $site->id, 'name' => $name,
        'rule_set' => ['include_patterns' => $include, 'seed_terms' => $include, 'exclude_patterns' => []],
    ]);
}

it('runs the full reconcile cascade: re-bucket, categories, re-route, town-tag, bounded republish', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SewerCo']);
    $sewer = rrSilo($site, 'Sewer', ['sewer']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);

    // An orphaned published post that mentions the silo term AND a coverage town → rerouted + town-tagged.
    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford Sewer Main Break', 'body' => 'A sewer line failed in Cranford this week.',
        'silo_id' => null, 'matched_silo_id' => null,
    ]);

    // An unbucketed keyword that matches the silo → re-bucketed.
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sewer repair cranford']);

    // A live location page for that town → its local feed changed, so it is republished.
    $locationPage = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Cranford, NJ', 'slug' => 'cranford-nj', 'wp_post_id' => 88,
    ]);

    $report = app(RebuildReconciler::class)->reconcile($site);

    expect($report->structureRebuilt)->toBeFalse()
        ->and($report->pagesAdded)->toBe(0)
        ->and($report->keywordsRebucketed)->toBe(1)
        ->and($report->categoriesQueued)->toBeTrue()
        ->and($report->postsRerouted)->toBe(1)
        ->and($report->townsTagged)->toBe(1)
        ->and($report->republishedPosts)->toBe(1)
        ->and($report->republishedLocationPages)->toBe(1)
        ->and($report->ok())->toBeTrue();

    expect($post->fresh()->silo_id)->toBe($sewer->id);

    Queue::assertPushed(SyncSiloCategories::class);
    // Two PublishContent jobs: the rerouted post + the changed-town location page.
    Queue::assertPushed(PublishContent::class, 2);
});

it('does not republish a location page whose town did not change', function () {
    Queue::fake();
    $site = Site::factory()->create();
    rrSilo($site, 'Sewer', ['sewer']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Westfield']);

    // The post tags Cranford — Westfield's feed is untouched, so its page is not republished.
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford story', 'body' => 'A sewer job in Cranford.', 'silo_id' => null, 'matched_silo_id' => null,
    ]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => 'Westfield, NJ', 'slug' => 'westfield-nj', 'wp_post_id' => 90,
    ]);

    $report = app(RebuildReconciler::class)->reconcile($site);

    expect($report->republishedLocationPages)->toBe(0);
});

it('is idempotent — a second run re-routes nothing and queues no republish', function () {
    Queue::fake();
    $site = Site::factory()->create();
    rrSilo($site, 'Sewer', ['sewer']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford Sewer', 'body' => 'Sewer work in Cranford.', 'silo_id' => null, 'matched_silo_id' => null,
    ]);

    app(RebuildReconciler::class)->reconcile($site);   // first run reroutes + tags + republishes
    $second = app(RebuildReconciler::class)->reconcile($site);

    expect($second->postsRerouted)->toBe(0)
        ->and($second->townsTagged)->toBe(1)      // still tagged, but nothing CHANGED
        ->and($second->tagsAdded)->toBe(0)
        ->and($second->republishedPosts)->toBe(0)
        ->and($second->republishedLocationPages)->toBe(0);
});

it('the command runs the cascade and reports the summary', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SewerCo']);
    rrSilo($site, 'Sewer', ['sewer']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford Sewer', 'body' => 'Sewer work in Cranford.', 'silo_id' => null, 'matched_silo_id' => null,
    ]);

    Artisan::call('launchpad:rebuild-reconcile', ['site' => $site->id]);

    expect(Artisan::output())->toContain('re-routed')->toContain('queued for republish');
    Queue::assertPushed(PublishContent::class);
});
