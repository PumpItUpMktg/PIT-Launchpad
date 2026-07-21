<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
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
