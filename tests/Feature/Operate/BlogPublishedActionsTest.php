<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\PublishContent;
use App\Models\Connection;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function publishedPost(Site $site, array $overrides = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford Sewer Costs Rising', 'slug' => 'cranford-sewer-costs', 'wp_post_id' => 88,
        'body' => '<p>Real article body.</p>',
    ], $overrides));
}

test('Re-push a published post dispatches the idempotent PublishContent job', function () {
    Bus::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $post = publishedPost($site);

    Livewire::test(OperateBlog::class, ['tab' => 'published'])->call('repushPost', $post->id);

    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $post->id);
});

test('Re-push is refused for an undrafted post (never pushes an empty body)', function () {
    Bus::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $post = publishedPost($site, ['body' => null]); // no drafted body

    Livewire::test(OperateBlog::class, ['tab' => 'published'])->call('repushPost', $post->id);

    Bus::assertNotDispatched(PublishContent::class);
});

test('Take down moves a blog post back to candidate (re-enters the funnel, leaves the Published lane)', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    // wp_post_id null → not on WP, so takedown needs no HTTP call and just flips it out of the live lane.
    $post = publishedPost($site, ['wp_post_id' => null]);

    Livewire::test(OperateBlog::class, ['tab' => 'published'])->call('takeDownPost', $post->id);

    expect($post->fresh()->status)->toBe(ContentStatus::Candidate) // back in the funnel, not queued-for-publish
        ->and($post->fresh()->wp_post_id)->toBeNull();
});

test('the in-flight lane flags an approved post stuck past the stall threshold', function () {
    $site = Site::factory()->create();
    // Both are released to the Publish queue (approved + released → in-flight lane).
    $released = ['released_to_publish_at' => now()->toIso8601String()];
    $fresh = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved, 'title' => 'Fresh', 'meta' => $released]);
    $stuck = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved, 'title' => 'Stuck', 'meta' => $released]);
    // Backdate the stuck one past the threshold (query-builder update doesn't touch timestamps).
    Content::withoutGlobalScope(SiteScope::class)->whereKey($stuck->id)
        ->update(['updated_at' => now()->subSeconds(BlogBoard::STALLED_AFTER_SECONDS + 60)]);

    $rows = collect(app(BlogBoard::class)->publishing($site->id))->keyBy('title');

    expect($rows['Stuck']['stalled'])->toBeTrue()
        ->and($rows['Fresh']['stalled'])->toBeFalse();
});

test('Publish from the Approved stage falls back to INLINE when the worker is stalled (no hang at "queued to publish")', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 654, 'status' => 'publish', 'skipped' => false])]);
    $site = Site::factory()->create(['domain_url' => 'https://inline.example']);
    session(['guided_site_id' => $site->id]);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://inline.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);
    // A dead failed job → QueueHealth reports the worker stalled.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);
    // The two-gate flow: the post is already Approved (QA-passed); Publish is the deliberate push.
    $approved = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'title' => 'Mine Drainage and Your Home', 'slug' => 'mine-drainage', 'body' => '<p>Real drafted body.</p>',
    ]);

    Bus::fake(); // prove Publish did NOT lean on the (dead) queue
    Livewire::test(OperateBlog::class, ['tab' => 'approved'])->call('publish', $approved->id);

    expect($approved->fresh()->status)->toBe(ContentStatus::Published)   // published inline, not left queued
        ->and($approved->fresh()->wp_post_id)->toBe(654);
    Bus::assertNotDispatched(PublishContent::class);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/content'));
});

test('Publish now runs the publish inline and pushes to WordPress without the worker', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 321, 'status' => 'publish', 'skipped' => false])]);
    $site = Site::factory()->create(['domain_url' => 'https://inline.example']);
    session(['guided_site_id' => $site->id]);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://inline.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'title' => 'Stuck Post', 'slug' => 'stuck-post', 'body' => '<p>Real body.</p>',
    ]);

    Livewire::test(OperateBlog::class, ['tab' => 'candidates'])->call('publishNowSync', $post->id);

    expect($post->fresh()->status)->toBe(ContentStatus::Published)
        ->and($post->fresh()->wp_post_id)->toBe(321);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/content'));
});

test('a published post renders the shared content-card row with its index chip from durable page_index_states', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = publishedPost($site, ['title' => 'Cranford Sewer Costs Rising', 'slug' => 'cranford-sewer-costs']);
    // The durable PASS verdict (the A2 flush source) — NOT the per-URL inspector cache the board read before.
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $post->id,
        'url' => 'https://spg.example/cranford-sewer-costs', 'url_normalized' => '/cranford-sewer-costs',
        'index_verdict' => 'PASS',
    ]);

    $card = collect(app(BlogBoard::class)->published($site->id))
        ->flatMap(fn (array $g) => $g['articles'])->firstWhere('id', (string) $post->id);

    expect($card)->not->toBeNull()
        ->and($card['type_label'])->toBe('Blog')            // the shared card shape, not the old hand-rolled array
        ->and($card['url'])->toBe('https://spg.example/cranford-sewer-costs')
        ->and($card['indexed'])->toBeTrue()
        ->and($card['index_label'])->toBe('Indexed')
        ->and($card['index_tone'])->toBe('good');
});

test('a published post with no verdict row reads "Not yet checked" — never "Not indexed" from an absent verdict', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = publishedPost($site, ['slug' => 'no-verdict-yet']);

    $card = collect(app(BlogBoard::class)->published($site->id))
        ->flatMap(fn (array $g) => $g['articles'])->firstWhere('id', (string) $post->id);

    expect($card['index_label'])->toBe('Not yet checked')
        ->and($card['index_state'])->toBe('unchecked')
        ->and($card['indexed'])->toBeFalse();
});

test('the publishing indicator lists posts in flight (approved / rendering / publishing)', function () {
    $site = Site::factory()->create();
    foreach ([ContentStatus::Approved, ContentStatus::Rendering, ContentStatus::Publishing] as $i => $status) {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => $status, 'title' => "InFlight {$i}",
            // Approved shows on the Publish queue only once released; rendering/publishing are already in flight.
            'meta' => $status === ContentStatus::Approved ? ['released_to_publish_at' => now()->toIso8601String()] : null,
        ]);
    }
    // A published + a needs_review post must NOT appear in the in-flight list.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Live']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'title' => 'Draft']);

    $rows = app(BlogBoard::class)->publishing($site->id);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('title')->all())->not->toContain('Live', 'Draft')
        ->and(collect($rows)->pluck('state')->all())->toContain('queued to publish', 'rendering image', 'pushing to WordPress');
});
