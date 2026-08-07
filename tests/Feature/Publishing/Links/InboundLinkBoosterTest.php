<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Links\InboundLinkBooster;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PublishHarness;

/** A published silo page whose prose already names the anchor phrase, with its GSC impression total. */
function ilbSourcePage(Site $site, string $siloId, string $slug, string $prose, int $impressions, array $extra = []): Content
{
    $page = Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_id' => $siloId,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(100, 999),
        'slug' => $slug,
        'title' => Str::headline($slug),
        'slot_payload' => ['intro' => $prose],
    ], $extra));

    if ($impressions > 0) {
        DB::table('gsc_url_daily')->insert([
            'id' => (string) Str::ulid(),
            'site_id' => $site->id,
            'grain_hash' => Grain::hash([$site->id, '2025-06-01', $slug]),
            'date' => '2025-06-01',
            'url' => 'https://apex.example/'.$slug.'/',
            'impressions' => $impressions,
            'clicks' => 0,
            'ctr' => 0,
            'position' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $page;
}

/** A revived blog post routed to a silo, carrying the reviver's winning query as its natural anchor. */
function ilbRevivedPost(Site $site, string $siloId, array $meta = ['revived_query' => 'drain cleaning', 'revived_from_urls' => ['/old-drains']]): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $siloId,
        'kind' => ContentKind::Post,
        'status' => ContentStatus::Published,
        'slug' => 'why-my-drain-keeps-clogging',
        'title' => 'Why My Drain Keeps Clogging',
        'body' => '<p>Some drafted body.</p>',
        'meta' => $meta,
    ]);
}

beforeEach(function () {
    Queue::fake();
    config()->set('launchpad.internal_linking.inbound_boost.mode', 'revivals');
});

it('links a revived post from the two strongest indexed same-silo pages, capped', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;

    $strong = ilbSourcePage($site, $siloId, 'main-drain-line', 'We handle drain cleaning on every main line.', 9000);
    $middle = ilbSourcePage($site, $siloId, 'kitchen-sink', 'Kitchen drain cleaning done right.', 4000);
    $weak = ilbSourcePage($site, $siloId, 'bathroom-drain', 'Bathroom drain cleaning too.', 100);

    $post = ilbRevivedPost($site, $siloId);

    $linked = app(InboundLinkBooster::class)->boost($post);

    expect($linked)->toHaveCount(2)
        ->toContain((string) $strong->id)
        ->toContain((string) $middle->id)
        ->not->toContain((string) $weak->id);

    // The two winners now carry an inbound link to the post; the third is untouched.
    expect(json_encode($strong->fresh()->slot_payload))->toContain('/why-my-drain-keeps-clogging')
        ->and(json_encode($middle->fresh()->slot_payload))->toContain('/why-my-drain-keeps-clogging')
        ->and(json_encode($weak->fresh()->slot_payload))->not->toContain('/why-my-drain-keeps-clogging');

    Queue::assertPushed(PublishContent::class, 2);
});

it('never fabricates a link — a page without the natural phrase is skipped', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;

    $page = ilbSourcePage($site, $siloId, 'sewer-line', 'We repair and replace sewer lines.', 9000);
    $post = ilbRevivedPost($site, $siloId);

    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);
    expect(json_encode($page->fresh()->slot_payload))->not->toContain('/why-my-drain-keeps-clogging');
    Queue::assertNothingPushed();
});

it('excludes zero-impression (not-yet-indexed) pages as sources', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;

    ilbSourcePage($site, $siloId, 'unindexed-page', 'Full drain cleaning menu here.', 0);
    $post = ilbRevivedPost($site, $siloId);

    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);
    Queue::assertNothingPushed();
});

it('skips a locked / locally-edited source page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;

    ilbSourcePage($site, $siloId, 'locked-page', 'Drain cleaning specialists.', 9000, ['locally_edited' => true]);
    $post = ilbRevivedPost($site, $siloId);

    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);
    Queue::assertNothingPushed();
});

it('only touches pages in the post\'s own silo', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;
    $otherSilo = Silo::factory()->create(['site_id' => $site->id])->id;

    ilbSourcePage($site, $otherSilo, 'other-silo-page', 'Drain cleaning mentioned here too.', 9000);
    $post = ilbRevivedPost($site, $siloId);

    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);
    Queue::assertNothingPushed();
});

it('respects the rollout gate: revivals-only skips an ordinary post but `all` links it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;
    ilbSourcePage($site, $siloId, 'main-line', 'We do drain cleaning daily.', 9000);

    // Ordinary post: no revival meta, but a target keyword the source page names.
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'drain cleaning']);
    $post = Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $siloId,
        'kind' => ContentKind::Post,
        'status' => ContentStatus::Published,
        'slug' => 'drain-tips',
        'title' => 'Drain Tips',
        'body' => '<p>Body.</p>',
        'target_keyword_id' => $keyword->id,
        'meta' => ['angle_hint' => 'tips'],
    ]);

    // In revivals mode, a non-revived post is left alone.
    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);
    Queue::assertNothingPushed();

    // Flip to `all` — now the keyword anchor is used.
    config()->set('launchpad.internal_linking.inbound_boost.mode', 'all');
    expect(app(InboundLinkBooster::class)->boost($post->fresh()))->toHaveCount(1);
    Queue::assertPushed(PublishContent::class, 1);
});

it('is idempotent — a re-run adds no second link', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloId = Silo::factory()->create(['site_id' => $site->id])->id;
    $page = ilbSourcePage($site, $siloId, 'main-drain', 'Drain cleaning across the metro.', 9000);
    $post = ilbRevivedPost($site, $siloId);

    app(InboundLinkBooster::class)->boost($post);
    expect(app(InboundLinkBooster::class)->boost($post))->toBe([]);

    // Exactly one anchor to the post survives.
    $links = substr_count((string) json_encode($page->fresh()->slot_payload), '/why-my-drain-keeps-clogging');
    expect($links)->toBe(1);
});

it('fires from the publish path — a revived post going live gets an inbound link woven in', function () {
    Queue::fake();
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 777, 'status' => 'publish', 'skipped' => false], 200)]);

    $site = PublishHarness::site(); // https://apex.example
    // Pre-mapped category so the publish path's silo sync is a no-op (no unfaked /silo call).
    $siloId = Silo::factory()->create(['site_id' => $site->id, 'wp_category_id' => 55])->id;

    $source = ilbSourcePage($site, $siloId, 'main-drain-line', 'We handle drain cleaning on every main line.', 9000);

    $post = Content::factory()->post()->create([
        'site_id' => $site->id,
        'silo_id' => $siloId,
        'status' => ContentStatus::Approved,
        'slug' => 'why-my-drain-keeps-clogging',
        'title' => 'Why My Drain Keeps Clogging',
        'body' => '<p>Some drafted body.</p>',
        'meta' => ['revived_query' => 'drain cleaning', 'revived_from_urls' => ['/old-drains']],
    ]);

    app(PublishContentService::class)->publish($post);

    expect(json_encode($source->fresh()->slot_payload))->toContain('/why-my-drain-keeps-clogging');
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === (string) $source->id);
});
