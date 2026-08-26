<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Publishing\Links\IndexBooster;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** A published page that Google reports as indexed (index_verdict=PASS), with a prose slot to link from. */
function ixSource(Site $site, string $slug, array $extra = []): Content
{
    $page = Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(100, 999),
        'slug' => $slug,
        'title' => Str::headline($slug),
        'slot_payload' => ['intro' => 'We handle every kind of job across the area with a written guarantee.'],
    ], $extra));

    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $page->id,
        'url' => 'https://apex.example/'.$slug.'/', 'url_normalized' => $slug,
        'index_verdict' => 'PASS',
    ]);

    return $page;
}

/** A newly-published page with no PASS index state — the boost target. */
function ixNewTarget(Site $site, string $slug, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(100, 999),
        'slug' => $slug,
        'title' => Str::headline($slug),
        'published_at' => now()->subDays(3),
        'slot_payload' => ['intro' => 'Fresh page.'],
    ], $extra));
}

it('links a new unindexed page from an indexed source and re-pushes the source', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $source = ixSource($site, 'sump-pump-repair-union');
    $target = ixNewTarget($site, 'sump-pump-repair-cranford');

    $r = app(IndexBooster::class)->boost($site, apply: true);

    expect($r['targets'])->toBe(1)
        ->and($r['sources_available'])->toBe(1)
        ->and($r['links'])->toBe(1)
        ->and($r['sources_repushed'])->toBe(1);

    // The controlled "Related" block landed in the source's prose slot, pointing at the target.
    $slots = $source->refresh()->slot_payload;
    expect($slots['intro'])->toContain('lp-related-inline')
        ->and($slots['intro'])->toContain('href="/sump-pump-repair-cranford"');

    Queue::assertPushed(PublishContent::class, fn (PublishContent $j): bool => $j->contentId === (string) $source->id);
});

it('does not target a page that is already indexed', function () {
    Queue::fake();
    $site = Site::factory()->create();
    ixSource($site, 'indexed-a');
    ixSource($site, 'indexed-b');   // both indexed → no unindexed target

    $r = app(IndexBooster::class)->boost($site, apply: true);

    expect($r['targets'])->toBe(0)->and($r['links'])->toBe(0);
    Queue::assertNothingPushed();
});

it('skips a source that already links the target, and locked sources', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $target = ixNewTarget($site, 'new-page');
    // Source already links the target → idempotent skip.
    ixSource($site, 'already-links', ['slot_payload' => ['intro' => 'See our <a href="/new-page">new page</a> for details here.']]);
    // Locked source → never edited.
    ixSource($site, 'locked-src', ['locked' => true]);

    $r = app(IndexBooster::class)->boost($site, apply: true);

    expect($r['links'])->toBe(0);
    Queue::assertNothingPushed();
});

it('dry-run reports the plan without editing or re-pushing', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $source = ixSource($site, 'src-page');
    ixNewTarget($site, 'target-page');

    $r = app(IndexBooster::class)->boost($site, apply: false);

    expect($r['links'])->toBe(1)->and($r['applied'])->toBeFalse();
    // Nothing actually changed.
    expect($source->refresh()->slot_payload['intro'])->not->toContain('lp-related-inline');
    Queue::assertNothingPushed();
});

it('caps sources per target and never links a page to itself', function () {
    Queue::fake();
    config()->set('launchpad.internal_linking.index_boost.max_sources_per_target', 2);
    $site = Site::factory()->create();
    foreach (['a', 'b', 'c', 'd'] as $s) {
        ixSource($site, "src-{$s}");
    }
    ixNewTarget($site, 'the-target');

    $r = app(IndexBooster::class)->boost($site, apply: true);

    expect($r['links'])->toBe(2);   // capped at 2 of the 4 available sources
});

it('runs the command and reports', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    ixSource($site, 'src');
    ixNewTarget($site, 'tgt');

    $this->artisan('launchpad:boost-indexing', ['site' => $site->id])
        ->expectsOutputToContain('Added 1 inbound link')
        ->assertExitCode(0);
});
