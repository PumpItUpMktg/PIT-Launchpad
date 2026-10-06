<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\Coverage\Reachability;
use App\Publishing\Links\LinkInjector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** A site whose live sitemap lists some pages and not others, with one indexed hub linking one of them. */
function reachSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'served_towns' => []]);
    $page = fn (string $title, string $slug, array $extra = []) => Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'location_id' => null, 'parent_location_id' => $office->id, 'title' => $title, 'slug' => $slug, 'wp_post_id' => random_int(100, 999),
        'published_at' => now()->subDays(14), 'slot_payload' => ['intro' => 'Dry basements across the county.'],
    ], $extra));
    $hub = $page('Hackettstown office', 'hackettstown-nj', ['location_id' => $office->id, 'parent_location_id' => null]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $hub->id, 'url' => 'https://spg.example/hackettstown-nj/', 'url_normalized' => 'https://spg.example/hackettstown-nj', 'coverage_state' => 'indexed', 'index_verdict' => 'PASS', 'indexed_at' => now()->subDays(5)]);

    $linked = $page('Washington, NJ', 'washington-nj');          // in the sitemap, linked from the indexed hub → reachable
    // A post routed to no silo: in the sitemap, but nothing links to it → orphan (a town page under an indexed
    // hub is never an orphan — the hub's town grid links every published town).
    $orphan = Content::factory()->post()->create(['site_id' => $site->id, 'silo_id' => null, 'matched_silo_id' => null, 'status' => 'published', 'title' => 'Where Sump Water Should Go', 'slug' => 'where-sump-water-should-go', 'wp_post_id' => 77, 'published_at' => now()->subDays(14), 'body' => '<p>Discharge lines and where they may end.</p>']);
    $missing = $page('Allamuchy, NJ', 'allamuchy-nj');           // NOT in the live sitemap → not_in_sitemap
    $drifted = $page('Hope, NJ', 'hope-nj');                     // the site serves it at /hope-nj-2/ → url_mismatch
    foreach ([$linked, $orphan, $missing, $drifted] as $p) {
        PageIndexState::create(['site_id' => $site->id, 'content_id' => $p->id, 'url' => "https://spg.example/{$p->slug}/", 'url_normalized' => "https://spg.example/{$p->slug}", 'coverage_state' => IndexCoverageState::Unknown->value, 'index_verdict' => IndexCoverageState::Unknown->value, 'last_inspected_at' => now()]);
    }
    // The hub links Washington (an inbound link from an indexed page).
    app(LinkInjector::class)->appendRelated($hub, 'Washington, NJ', '/washington-nj');

    Http::fake(['https://spg.example/sitemap-content.xml' => Http::response(
        '<?xml version="1.0"?><urlset><url><loc>https://spg.example/hackettstown-nj/</loc></url><url><loc>https://spg.example/washington-nj/</loc></url><url><loc>https://spg.example/where-sump-water-should-go/</loc></url><url><loc>https://spg.example/hope-nj-2/</loc></url></urlset>',
        200, ['Content-Type' => 'application/xml'],
    )]);

    return compact('site', 'office', 'hub', 'linked', 'orphan', 'missing', 'drifted');
}

/** Bind a WordPress diagnose that reports every page found at its own slug, except the drifted one. */
function fakeDiagnose(array $f): void
{
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('diagnoseContent')->andReturnUsing(function (string $contentId, string $slug) use ($f): array {
        $live = $contentId === $f['drifted']->id ? 'hope-nj-2' : $slug;

        return ['content_id' => $contentId, 'found' => true, 'permalink' => "https://spg.example/{$live}/", 'post_name' => $live, 'slug_drifted' => $live !== $slug];
    });
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);
}

it('explains each page unknown to Google: not in the live sitemap, served at a different URL, orphaned, or simply not visited yet', function () {
    $f = reachSite();
    fakeDiagnose($f);

    $report = app(Reachability::class)->for($f['site']);
    $byTitle = collect($report['pages'])->keyBy('title');

    expect($report['sitemap']['fetched'])->toBeTrue()->and($report['sitemap']['urls'])->toBe(4)
        ->and($report['gsc']['connected'])->toBeFalse()
        ->and($byTitle['Washington, NJ']['verdict'])->toBe(Reachability::REACHABLE)
        ->and($byTitle['Washington, NJ']['inbound_indexed'])->toBe(1)
        ->and($byTitle['Where Sump Water Should Go']['verdict'])->toBe(Reachability::ORPHAN)
        ->and($byTitle['Allamuchy, NJ']['verdict'])->toBe(Reachability::NOT_IN_SITEMAP)
        ->and($byTitle['Allamuchy, NJ']['in_sitemap'])->toBeFalse()
        ->and($byTitle['Hope, NJ']['verdict'])->toBe(Reachability::URL_MISMATCH)
        ->and($byTitle['Hope, NJ']['live_permalink'])->toBe('https://spg.example/hope-nj-2/')
        ->and($report['by_verdict'])->toBe([Reachability::NOT_IN_SITEMAP => 1, Reachability::ORPHAN => 1, Reachability::REACHABLE => 1, Reachability::URL_MISMATCH => 1]);
});

it('--fix re-pushes the pages whose push drifted or fell out of the sitemap and links the orphan from the indexed hub', function () {
    Queue::fake();
    $f = reachSite();
    fakeDiagnose($f);

    $this->artisan('launchpad:check-reachability', ['--site' => 'SPG', '--fix' => true])
        ->expectsOutputToContain('Pages checked: 4')
        ->expectsOutputToContain('Fix: re-pushed 2 page(s) · sitemap not resubmitted · linked 1 orphan(s) from ranking pages')
        ->assertSuccessful();

    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === $f['missing']->id);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === $f['drifted']->id);
    expect($f['hub']->fresh()->slot_payload['intro'])->toContain('href="/where-sump-water-should-go"');
});

it('works without the live permalink read (sitemap + links only) when WordPress cannot be reached', function () {
    $f = reachSite();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andThrow(new RuntimeException('no connection'));
    app()->instance(WordpressClientFactory::class, $factory);

    $byTitle = collect(app(Reachability::class)->for($f['site'])['pages'])->keyBy('title');
    expect($byTitle['Hope, NJ']['verdict'])->toBe(Reachability::NOT_IN_SITEMAP)   // without the live read, drift shows as "not in the sitemap" (it lists /hope-nj-2/, not /hope-nj/)
        ->and($byTitle['Hope, NJ']['in_sitemap'])->toBeFalse()
        ->and($byTitle['Allamuchy, NJ']['verdict'])->toBe(Reachability::NOT_IN_SITEMAP);
});

it('with no live read, the sitemap reveals a town served flat instead of nested under its hub — a URL mismatch to re-push', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG2', 'domain_url' => 'https://spg2.example']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Downingtown office', 'served_towns' => []]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andThrow(new RuntimeException('WordPress /content/diagnose returned HTTP 404'));
    app()->instance(WordpressClientFactory::class, $factory);
    // Chester is nested under the Downingtown hub in Launchpad, but the live sitemap serves it flat.
    $chester = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'location_id' => null, 'parent_location_id' => $office->id, 'title' => 'Chester, PA', 'slug' => 'downingtown-pa/chester-pa', 'wp_post_id' => 321,
        'published_at' => now()->subDays(14), 'slot_payload' => ['intro' => 'x'],
    ]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $chester->id, 'url' => 'https://spg2.example/downingtown-pa/chester-pa/', 'url_normalized' => 'https://spg2.example/downingtown-pa/chester-pa', 'coverage_state' => IndexCoverageState::Unknown->value, 'index_verdict' => IndexCoverageState::Unknown->value, 'last_inspected_at' => now()]);
    Http::fake(['https://spg2.example/sitemap-content.xml' => Http::response(
        '<?xml version="1.0"?><urlset><url><loc>https://spg2.example/downingtown-pa/</loc></url><url><loc>https://spg2.example/chester-pa/</loc></url></urlset>',
        200, ['Content-Type' => 'application/xml'],
    )]);

    $report = app(Reachability::class)->for($site);
    $row = collect($report['pages'])->firstWhere('title', 'Chester, PA');

    expect($report['live_error'])->toContain('HTTP 404')
        ->and($row['verdict'])->toBe(Reachability::URL_MISMATCH)
        ->and($row['live_permalink'])->toBe('https://spg2.example/chester-pa/')
        ->and($row['action'])->toContain('the slug drifted');
});
