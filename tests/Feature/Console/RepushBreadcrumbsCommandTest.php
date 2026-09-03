<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

it('re-pushes only the published silo-crumb pages: --dry-run reports per silo and queues nothing; a real run queues one PublishContent each', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Water Heaters']);

    // Two published spokes → affected.
    $spokeA = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-a']);
    $spokeB = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-b']);

    // Excluded: a Hub (the silo head), the silo's own pillar, an unpublished spoke, a page in no silo.
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Hub, 'slug' => 'wh-hub']);
    $pillar = Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'wh-pillar']);
    $silo->forceFill(['pillar_content_id' => $pillar->id])->save();
    Content::factory()->page()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'status' => ContentStatus::Candidate, 'slug' => 'wh-draft']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'silo_id' => null, 'page_type' => PageType::Service, 'slug' => 'no-silo']);

    // Dry run: reports, queues nothing.
    $this->artisan('launchpad:repush-breadcrumbs', ['--dry-run' => true])
        ->expectsOutputToContain('Water Heaters')
        ->expectsOutputToContain('2 page(s) across 1 silo(s).')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    // Real run: one idempotent PublishContent per affected page (the two spokes only).
    $this->artisan('launchpad:repush-breadcrumbs')->assertSuccessful();
    Queue::assertPushed(PublishContent::class, 2);
    foreach ([$spokeA, $spokeB] as $spoke) {
        Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $spoke->id);
    }
});
