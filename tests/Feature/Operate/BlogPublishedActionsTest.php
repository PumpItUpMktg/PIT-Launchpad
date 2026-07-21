<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\PublishContent;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

test('Take down flips the post back to approved (leaves the Published lane)', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    // wp_post_id null → not on WP, so takedown needs no HTTP call and just makes it republishable.
    $post = publishedPost($site, ['wp_post_id' => null]);

    Livewire::test(OperateBlog::class, ['tab' => 'published'])->call('takeDownPost', $post->id);

    expect($post->fresh()->status)->toBe(ContentStatus::Approved)
        ->and($post->fresh()->wp_post_id)->toBeNull();
});

test('the in-flight lane flags an approved post stuck past the stall threshold', function () {
    $site = Site::factory()->create();
    $fresh = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved, 'title' => 'Fresh']);
    $stuck = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved, 'title' => 'Stuck']);
    // Backdate the stuck one past the threshold (query-builder update doesn't touch timestamps).
    Content::withoutGlobalScope(SiteScope::class)->whereKey($stuck->id)
        ->update(['updated_at' => now()->subSeconds(BlogBoard::STALLED_AFTER_SECONDS + 60)]);

    $rows = collect(app(BlogBoard::class)->publishing($site->id))->keyBy('title');

    expect($rows['Stuck']['stalled'])->toBeTrue()
        ->and($rows['Fresh']['stalled'])->toBeFalse();
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

test('the publishing indicator lists posts in flight (approved / rendering / publishing)', function () {
    $site = Site::factory()->create();
    foreach ([ContentStatus::Approved, ContentStatus::Rendering, ContentStatus::Publishing] as $i => $status) {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => $status, 'title' => "InFlight {$i}",
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
