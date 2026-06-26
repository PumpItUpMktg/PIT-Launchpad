<?php

use App\Enums\BuildSource;
use App\Enums\PageType;
use App\Enums\SpokePageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\Spoke;

/** A service page materialized BEFORE the pin existed: kind=page, page_type=Service, no primary_service_id. */
function unpinnedServicePage(Site $site, string $serviceName, array $attrs = []): array
{
    $spoke = Spoke::factory()->create([
        'site_id' => $site->id, 'name' => $serviceName, 'is_pillar' => false, 'page_type' => SpokePageType::Service,
    ]);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => $serviceName]); // what the projection would have created
    $page = Content::factory()->page()->create(array_merge([
        'site_id' => $site->id, 'page_type' => PageType::Service, 'primary_service_id' => null,
    ], $attrs));
    BuildPage::factory()->create([
        'site_id' => $site->id, 'source' => BuildSource::Service, 'spoke_id' => $spoke->id, 'content_id' => $page->id,
    ]);

    return [$page, $service];
}

it('pins a service page to its projected service via the BuildPage → spoke link', function () {
    $site = Site::factory()->create();
    [$page, $service] = unpinnedServicePage($site, 'Toilet Replacement');

    $this->artisan('launchpad:backfill-page-service', ['--site' => $site->id])->assertSuccessful();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->primary_service_id)->toBe($service->id);
});

it('warns that a live page needs re-generate + re-publish to correct', function () {
    $site = Site::factory()->create();
    [$page] = unpinnedServicePage($site, 'Toilet Replacement', ['wp_post_id' => 42]);

    $this->artisan('launchpad:backfill-page-service', ['--site' => $site->id])
        ->expectsOutputToContain('re-generate then re-publish')
        ->assertSuccessful();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->primary_service_id)->not->toBeNull();
});

it('dry-run reports but writes nothing', function () {
    $site = Site::factory()->create();
    [$page] = unpinnedServicePage($site, 'Toilet Replacement');

    $this->artisan('launchpad:backfill-page-service', ['--site' => $site->id, '--dry-run' => true])->assertSuccessful();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->primary_service_id)->toBeNull();
});

it('force-pins a specific page to a given service with --service', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Service, 'primary_service_id' => null]);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Toilet Replacement']);

    $this->artisan('launchpad:backfill-page-service', ['content' => $page->id, '--service' => $service->id])->assertSuccessful();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->primary_service_id)->toBe($service->id);
});

it('skips a page whose service cannot be resolved (no spoke link, no --service)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Service, 'primary_service_id' => null]);

    $this->artisan('launchpad:backfill-page-service', ['content' => $page->id])
        ->expectsOutputToContain('could not resolve a service')
        ->assertSuccessful();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->primary_service_id)->toBeNull();
});
