<?php

use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Site;
use App\Operator\PortfolioHealth;
use App\Operator\SiteHealth;

function portfolio(): PortfolioHealth
{
    return app(PortfolioHealth::class);
}

test('a site rolls up its review backlog, failures, publishing and compromised creds', function () {
    $site = Site::factory()->create();
    Content::factory()->count(2)->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::RenderFailed]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);

    $health = portfolio()->forSite($site);

    expect($health->reviewBacklog)->toBe(2)
        ->and($health->renderFailed)->toBe(1)
        ->and($health->publishedThisWeek)->toBe(1)
        ->and($health->compromisedCredentials)->toBe(1)
        ->and($health->needsAttention())->toBeTrue();
});

test('the portfolio lists every tenant, most urgent first', function () {
    $calm = Site::factory()->create();

    $urgent = Site::factory()->create();
    Content::factory()->create(['site_id' => $urgent->id, 'status' => ContentStatus::RenderFailed]);
    Content::factory()->count(5)->create(['site_id' => $urgent->id, 'status' => ContentStatus::NeedsReview]);

    $all = portfolio()->all();

    expect($all)->toHaveCount(2)
        // The site with a render failure + big backlog sorts to the top.
        ->and($all->first()->site->id)->toBe($urgent->id)
        ->and($all->first())->toBeInstanceOf(SiteHealth::class);

    $calmHealth = $all->firstWhere(fn (SiteHealth $h) => $h->site->id === $calm->id);
    expect($calmHealth->needsAttention())->toBeFalse();
});
