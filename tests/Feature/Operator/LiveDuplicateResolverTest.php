<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Location;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\LiveDuplicateResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function locPageR(Site $s, string $title, string $slug, ?string $locationId, ?string $parentId, ?int $wpId = null): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug,
        'location_id' => $locationId, 'parent_location_id' => $parentId, 'wp_post_id' => $wpId,
    ]);
}

/** Mock the WP factory so redirect-push + delete don't hit the network. */
function fakeWp(): void
{
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('upsertRedirects')->andReturn([]);
    $client->shouldReceive('deleteContent')->andReturnTrue();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);
}

it('plans a landing↔town pair: keep the landing, 301 the nested town → it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $landing = locPageR($site, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null);
    $town = locPageR($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id);

    $plan = app(LiveDuplicateResolver::class)->plan($site);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['ambiguous'])->toBeFalse()
        ->and($plan[0]['keeper']['content_id'])->toBe($landing->id)
        ->and($plan[0]['keeper']['role'])->toBe('landing')
        ->and($plan[0]['losers'])->toHaveCount(1)
        ->and($plan[0]['losers'][0]['content_id'])->toBe($town->id)
        ->and($plan[0]['losers'][0]['from'])->toBe('/hoboken-nj/hoboken-nj')
        ->and($plan[0]['losers'][0]['to'])->toBe('/hoboken-nj');
});

it('plans a town↔town duplicate: keep the clean slug, 301 the numbered one', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $doylestown = Location::factory()->create(['site_id' => $site->id]);
    $clean = locPageR($site, 'Buckingham, PA', 'doylestown-pa/buckingham-pa', null, $doylestown->id);
    $dupe = locPageR($site, 'Buckingham, PA', 'doylestown-pa/buckingham-pa-2', null, $doylestown->id);

    $plan = app(LiveDuplicateResolver::class)->plan($site);

    expect($plan[0]['keeper']['content_id'])->toBe($clean->id)
        ->and($plan[0]['losers'][0]['content_id'])->toBe($dupe->id)
        ->and($plan[0]['losers'][0]['from'])->toBe('/doylestown-pa/buckingham-pa-2')
        ->and($plan[0]['losers'][0]['to'])->toBe('/doylestown-pa/buckingham-pa');
});

it('flags an ambiguous group with no clear keeper and never touches it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    // Two numbered towns, no landing, no single clean slug → ambiguous.
    locPageR($site, 'Twin, NJ', 'x/twin-nj-2', null, $loc->id);
    locPageR($site, 'Twin, NJ', 'x/twin-nj-3', null, $loc->id);

    $plan = app(LiveDuplicateResolver::class)->plan($site);

    expect($plan[0]['ambiguous'])->toBeTrue()
        ->and($plan[0]['keeper'])->toBeNull()
        ->and(app(LiveDuplicateResolver::class)->apply($site))->toBe([]); // nothing applied
});

it('applies: writes the 301, verifies it is SERVING, then removes the loser page', function () {
    fakeWp();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $landing = locPageR($site, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null, wpId: 100);
    $town = locPageR($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id, wpId: 200);

    // The live loser URL answers 301 → the landing (redirect is serving).
    Http::fake([
        'spg.example/hoboken-nj/hoboken-nj/' => Http::response('', 301, ['Location' => 'https://spg.example/hoboken-nj/']),
        '*' => Http::response('', 200),
    ]);

    $out = app(LiveDuplicateResolver::class)->apply($site);

    expect($out)->toHaveCount(1)
        ->and($out[0]['redirected'])->toBeTrue()
        ->and($out[0]['verified'])->toBeTrue()
        ->and($out[0]['removed'])->toBeTrue()
        // the redirect row is persisted
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/hoboken-nj/hoboken-nj')->first()->to_url)->toBe('/hoboken-nj')
        // the loser is soft-deleted; the keeper stays live
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($town->id))->toBeNull()
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($landing->id))->not->toBeNull();
});

it('NEVER removes the page when the redirect is not confirmed serving (no 404 gap)', function () {
    fakeWp();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPageR($site, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null, wpId: 100);
    $town = locPageR($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id, wpId: 200);

    // The loser URL still serves 200 (redirect NOT live yet) — removal must be withheld.
    Http::fake(['*' => Http::response('', 200)]);

    $out = app(LiveDuplicateResolver::class)->apply($site);

    expect($out[0]['redirected'])->toBeTrue()
        ->and($out[0]['verified'])->toBeFalse()
        ->and($out[0]['removed'])->toBeFalse()
        ->and($out[0]['note'])->toContain('left live')
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($town->id))->not->toBeNull(); // page kept
});

it('command is report-only by default and writes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPageR($site, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null);
    $town = locPageR($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id);

    $code = Artisan::call('launchpad:resolve-live-duplicates', ['--site' => $site->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('301 [town] /hoboken-nj/hoboken-nj → /hoboken-nj')
        ->and($out)->toContain('would be written')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($town->id))->not->toBeNull();
});
