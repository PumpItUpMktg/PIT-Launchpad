<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishRedirects;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

function auditedSite(): Site
{
    $site = Site::factory()->create();
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'wp_post_id' => 41, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa',
    ]);
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/trooper', 'to_url' => '/trooper-pa', 'code' => 301, 'status' => 'active', 'source' => 'migration',
    ]);

    return $site;
}

it('still queues the redirect push on --apply --push when there is nothing to write', function () {
    Queue::fake();
    $site = auditedSite();

    $this->artisan('launchpad:audit-redirects', ['--site' => $site->id, '--apply' => true, '--push' => true])
        ->expectsOutputToContain('No legacy location redirects to add or fix.')
        ->expectsOutputToContain('Queued the redirect push to WordPress anyway')
        ->assertSuccessful();

    Queue::assertPushed(PublishRedirects::class, fn (PublishRedirects $job) => $job->siteId === $site->id);
});

it('never pushes from a preview', function () {
    Queue::fake();
    $site = auditedSite();

    $this->artisan('launchpad:audit-redirects', ['--site' => $site->id, '--push' => true])
        ->expectsOutputToContain('No legacy location redirects to add or fix.')
        ->assertSuccessful();

    Queue::assertNotPushed(PublishRedirects::class);
});
