<?php

use App\Enums\BeatabilityLane;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\PageIndexState;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Operate\LiveBoard;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

/** A published content row of a given kind/page_type on the site. */
function lbContent(Site $site, ContentKind $kind, ?PageType $type, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => $kind,
        'page_type' => $type,
        'status' => ContentStatus::Published,
        'published_at' => now(),
    ], $extra));
}

it('classifies every published type into the four buckets and counts them', function () {
    $site = Site::factory()->create();
    lbContent($site, ContentKind::Post, null);                       // blog
    lbContent($site, ContentKind::Page, PageType::Home);             // core
    lbContent($site, ContentKind::Page, PageType::Hub);              // core (Hub → Core)
    lbContent($site, ContentKind::Page, PageType::Service);          // service
    lbContent($site, ContentKind::Page, PageType::Location);         // town
    lbContent($site, ContentKind::Page, PageType::Location);         // town
    // A non-published page is never on the board.
    lbContent($site, ContentKind::Page, PageType::Service, ['status' => ContentStatus::Candidate]);

    expect(app(LiveBoard::class)->counts($site))
        ->toBe(['all' => 6, 'blog' => 1, 'core' => 2, 'service' => 1, 'town' => 2]);

    $rows = app(LiveBoard::class)->rows($site, 'all');
    expect($rows)->toHaveCount(6)
        ->and(collect($rows)->pluck('type')->sort()->values()->all())
        ->toBe(['blog', 'core', 'core', 'service', 'town', 'town']);
});

it('filters to the active tab', function () {
    $site = Site::factory()->create();
    lbContent($site, ContentKind::Post, null);
    lbContent($site, ContentKind::Page, PageType::Service);
    $town = lbContent($site, ContentKind::Page, PageType::Location);

    $rows = app(LiveBoard::class)->rows($site, 'town');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe((string) $town->id)
        ->and($rows[0]['type_label'])->toBe('Town');
});

it('"Not indexed" excludes pages Google confirms via page_index_states', function () {
    $site = Site::factory()->create();
    $indexed = lbContent($site, ContentKind::Page, PageType::Service, ['slug' => 'in']);
    $notIndexed = lbContent($site, ContentKind::Page, PageType::Service, ['slug' => 'out']);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $indexed->id, 'url' => 'https://x/in', 'url_normalized' => '/in', 'index_verdict' => 'PASS']);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $notIndexed->id, 'url' => 'https://x/out', 'url_normalized' => '/out', 'index_verdict' => 'NEUTRAL']);

    $rows = app(LiveBoard::class)->rows($site, 'all', ['not_indexed' => true]);
    expect(collect($rows)->pluck('id')->all())->toBe([(string) $notIndexed->id]);
});

it('"Not ranking" excludes pages whose target keyword has an organic rank', function () {
    $site = Site::factory()->create();
    $ranking = lbContent($site, ContentKind::Page, PageType::Service, ['slug' => 'r']);
    $notRanking = lbContent($site, ContentKind::Page, PageType::Service, ['slug' => 'nr']);
    $kw = Keyword::factory()->create(['site_id' => $site->id]);
    $ranking->forceFill(['target_keyword_id' => $kw->id])->save();
    PositionSnapshot::create([
        'site_id' => $site->id, 'keyword_id' => $kw->id, 'lane' => BeatabilityLane::Organic,
        'rank' => 7, 'captured_at' => now(),
    ]);

    $rows = app(LiveBoard::class)->rows($site, 'all', ['not_ranking' => true]);
    expect(collect($rows)->pluck('id')->all())->toBe([(string) $notRanking->id]);
});

it('carries the WP edit link only when the page is live (has a wp_post_id)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $live = lbContent($site, ContentKind::Page, PageType::Service, ['wp_post_id' => 42]);
    $notPushed = lbContent($site, ContentKind::Page, PageType::Service, ['wp_post_id' => null]);

    $rows = collect(app(LiveBoard::class)->rows($site, 'all'))->keyBy('id');
    expect($rows[(string) $live->id]['wp_url'])->toBe('https://apex.example/wp-admin/post.php?post=42&action=edit')
        ->and($rows[(string) $notPushed->id]['wp_url'])->toBeNull();
});
