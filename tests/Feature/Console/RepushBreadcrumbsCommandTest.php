<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

it('reports the affected silo-crumb PAGES by default and queues nothing; --execute queues one PublishContent each', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Water Heaters']);

    // Two published spokes → affected. The silo's pillar is live, so both resolve to a 3-item crumb.
    $spokeA = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-a']);
    $spokeB = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-b']);

    // Excluded: a Hub (the silo head), the silo's own pillar, an unpublished spoke, a page in no silo.
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Hub, 'slug' => 'wh-hub']);
    $pillar = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-pillar']);
    $silo->forceFill(['pillar_content_id' => $pillar->id])->save();
    Content::factory()->page()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'status' => ContentStatus::Candidate, 'slug' => 'wh-draft']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => null, 'page_type' => PageType::Service, 'slug' => 'no-silo']);

    // Default: report only, queue nothing.
    $this->artisan('launchpad:repush-breadcrumbs')
        ->expectsOutputToContain('Water Heaters')
        ->expectsOutputToContain('2 affected item(s) across 1 silo(s): 2 would emit a valid 3-item crumb, 0 a valid 2-item')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    // --execute: one idempotent PublishContent per affected page (the two spokes only).
    $this->artisan('launchpad:repush-breadcrumbs', ['--execute' => true])->assertSuccessful();
    Queue::assertPushed(PublishContent::class, 2);
    foreach ([$spokeA, $spokeB] as $spoke) {
        Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $spoke->id);
    }
});

it('includes POSTS and splits the report by whether the silo resolves to a live top page (3-item vs 2-item)', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    // Silo WITH a live top page (a published pillar) → its post resolves to a 3-item crumb.
    $resolves = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Resolves']);
    $pillar = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $resolves->id, 'page_type' => PageType::Service, 'slug' => 'r-pillar']);
    $resolves->forceFill(['pillar_content_id' => $pillar->id])->save(); // the pillar itself is excluded (self-ref)
    $postResolves = Content::factory()->post()->published()->create(['site_id' => $site->id, 'silo_id' => $resolves->id, 'slug' => 'post-resolves']);

    // Silo with NO live top page → its post drops the middle crumb to a valid 2-item.
    $noTop = Silo::factory()->create(['site_id' => $site->id, 'name' => 'NoTop']);
    $postNoTop = Content::factory()->post()->published()->create(['site_id' => $site->id, 'silo_id' => $noTop->id, 'slug' => 'post-notop']);

    // Report: two posts affected, one 3-item and one 2-item.
    $this->artisan('launchpad:repush-breadcrumbs')
        ->expectsOutputToContain('2 affected item(s) across 2 silo(s): 1 would emit a valid 3-item crumb, 1 a valid 2-item')
        ->expectsOutputToContain('no live top page in this silo')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    // --execute queues both posts (repushing is idempotent; a 2-item is still valid markup).
    $this->artisan('launchpad:repush-breadcrumbs', ['--execute' => true])->assertSuccessful();
    Queue::assertPushed(PublishContent::class, 2);
    foreach ([$postResolves, $postNoTop] as $post) {
        Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $post->id);
    }
});
