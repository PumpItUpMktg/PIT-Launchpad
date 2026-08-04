<?php

use App\Enums\ContentKind;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

it('re-pushes a named site\'s published posts (throttled)', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    foreach (range(1, 2) as $n) {
        Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post->value, 'status' => 'published', 'wp_post_id' => $n, 'slug' => 'p'.$n]);
    }

    $this->artisan('launchpad:repush-published', ['--site' => 'SPG', '--kind' => 'post'])
        ->expectsOutputToContain('Re-pushing 2')
        ->assertSuccessful();

    Queue::assertPushed(PublishContent::class, 2);
});

it('dry-run dispatches nothing', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post->value, 'status' => 'published', 'wp_post_id' => 1, 'slug' => 'p1']);

    $this->artisan('launchpad:repush-published', ['--site' => 'SPG', '--dry-run' => true])
        ->expectsOutputToContain('Would re-push')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('requires a site', function () {
    $this->artisan('launchpad:repush-published')->assertFailed();
});
