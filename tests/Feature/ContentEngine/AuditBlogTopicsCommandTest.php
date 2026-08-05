<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Site;

function auditBlogPost(Site $site, string $title, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'status' => ContentStatus::Published,
        'title' => $title,
        'slug' => $slug,
        'published_at' => now()->subDays(2),
    ]);
}

it('flags published posts the reactive gate would now reject, and leaves on-topic ones alone', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    auditBlogPost($site, 'Sump pump maintenance for a wet basement', 'sump-pump-maintenance');     // on-topic → kept
    auditBlogPost($site, 'County approves $22M sewer grant budget', 'sewer-grant-budget');          // off-topic finance
    auditBlogPost($site, 'New watershed drainage management plan adopted', 'watershed-plan');        // §8.6 config deny

    $this->artisan('launchpad:audit-blog-topics', ['--site' => 'SPG'])
        ->expectsOutputToContain('would REJECT')
        ->expectsOutputToContain('sewer-grant-budget')      // municipal-finance leak flagged
        ->expectsOutputToContain('watershed-plan')          // proves the §8.6 watershed deny-term is wired via config
        ->doesntExpectOutputToContain('sump-pump-maintenance') // the on-topic post is never flagged
        ->assertSuccessful();
});

it('reports a clean bill when every published post is on-topic', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    auditBlogPost($site, 'How a French drain keeps groundwater out of your basement', 'french-drain-basics');

    $this->artisan('launchpad:audit-blog-topics', ['--site' => 'SPG'])
        ->expectsOutputToContain('Nothing to prune')
        ->assertSuccessful();
});

it('errors for an unknown site', function () {
    $this->artisan('launchpad:audit-blog-topics', ['--site' => 'Nope Inc'])->assertFailed();
});
