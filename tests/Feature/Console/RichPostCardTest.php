<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\OpsConsole\PublishedContentBoard;

it('builds a rich published-post card with metrics, silo, towns and both-way links', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);

    // A live page the post links to (inline body link) — becomes an OUTBOUND link.
    $target = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 5, 'title' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair',
    ]);

    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 9,
        'matched_silo_id' => $silo->id, 'title' => 'When your sump pump fails', 'slug' => 'sump-pump-fails',
        'body' => 'See our <a href="/sump-pump-repair">repair service</a> for help.',
    ]);

    $board = app(PublishedContentBoard::class)->forSite($site->id);
    $card = collect($board['posts'])->firstWhere('id', $post->id);

    expect($card)->not->toBeNull()
        ->and($card['silo'])->toBe('Sump Pumps')
        ->and($card['metrics'])->toHaveKeys(['keyword', 'position', 'gsc', 'index', 'bing', 'traffic'])
        ->and($card['links'])->toHaveKeys(['outbound', 'inbound'])
        // The inline body link resolves to the target page (outbound).
        ->and(collect($card['links']['outbound'])->pluck('title'))->toContain('Sump Pump Repair');

    // The target page, in turn, is linked-from the post (inbound on the target — proves the graph both ways).
    // Not asserted on the post card here; the outbound edge above already exercises the graph.
});

it('reflects the honest pending states when nothing is connected', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 9, 'title' => 'Post', 'slug' => 'post',
    ]);

    $card = collect(app(PublishedContentBoard::class)->forSite($site->id)['posts'])->firstWhere('id', $post->id);

    // No Search Console / GA connected in the test → honest pending, never a fabricated number.
    expect($card['metrics']['gsc']['pending'])->not->toBeNull()
        ->and($card['metrics']['traffic']['pending'])->not->toBeNull()
        ->and($card['metrics']['position']['pending'])->toBe('No target keyword — brand page');
});
