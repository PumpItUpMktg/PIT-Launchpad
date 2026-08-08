<?php

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Filament\Console\Pages\BlogApproved;
use App\Filament\Console\Pages\BlogPreview;
use App\Models\Account;
use App\Models\Content;
use App\Models\Membership;
use App\Models\RenderJob;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use App\OpsConsole\PostPreview;
use Filament\Facades\Filament;
use Livewire\Livewire;

function apprCard(Site $site, array $overrides = []): Content
{
    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id,
        'status' => ContentStatus::Approved,
        'title' => 'Sump pump buying guide',
        'slug' => 'sump-pump-buying-guide',
        'body' => '<p>A real drafted body.</p>',
    ], $overrides));
}

it('routes an approved-but-not-released post to Approved, not Publish', function () {
    $site = Site::factory()->create();
    $post = apprCard($site);

    $board = app(BlogBoard::class);

    expect(collect($board->approved($site->id))->pluck('id'))->toContain($post->id)
        ->and(collect($board->publishing($site->id))->pluck('id'))->not->toContain($post->id);
});

it('release moves the post from Approved to the Publish queue', function () {
    $site = Site::factory()->create();
    $post = apprCard($site);

    app(ReviewActions::class)->release($post->fresh());

    $board = app(BlogBoard::class);
    expect($post->fresh()->isReleasedToPublish())->toBeTrue()
        ->and(collect($board->approved($site->id))->pluck('id'))->not->toContain($post->id)
        ->and(collect($board->publishing($site->id))->pluck('id'))->toContain($post->id)
        ->and($post->fresh()->status)->toBe(ContentStatus::Approved); // still approved — not yet pushed
});

it('send-back returns an approved post to Review and clears any release', function () {
    $site = Site::factory()->create();
    $post = apprCard($site);
    $post->releaseToPublish();

    app(ReviewActions::class)->returnToReview($post->fresh());

    expect($post->fresh()->status)->toBe(ContentStatus::NeedsReview)
        ->and($post->fresh()->isReleasedToPublish())->toBeFalse();
});

it('release is blocked when a required image failed to render', function () {
    $site = Site::factory()->create();
    $post = apprCard($site);
    RenderJob::factory()->create([
        'content_id' => $post->id, 'status' => RenderStatus::RenderFailed, 'required' => true,
    ]);

    $result = app(ReviewActions::class)->release($post->fresh());

    expect($result->isBlocked())->toBeTrue()
        ->and($post->fresh()->isReleasedToPublish())->toBeFalse();
});

it('the Approved page renders the card and sends to Publish', function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $post = apprCard($site, ['title' => 'Radon in the basement']);

    Livewire::test(BlogApproved::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Radon in the basement')
        ->call('release', $post->id);

    expect($post->fresh()->isReleasedToPublish())->toBeTrue();
});

it('PostPreview assembles hero image, SEO, towns and internal links both ways', function () {
    $site = Site::factory()->create();
    $target = apprCard($site, [
        'slug' => 'sewer-line-repair',
        'body' => '<p>Details. <a href="/sewer-camera-inspection">See our inspection page</a>.</p>',
        'meta' => ['seo' => ['title' => 'Sewer Line Repair | Guide', 'meta_description' => 'How sewer line repair works.']],
    ]);
    RenderJob::factory()->rendered()->create(['content_id' => $target->id]);

    // Another post links to the target's slug → an inbound link.
    apprCard($site, ['title' => 'Backups', 'slug' => 'sewer-backups', 'body' => '<p>See <a href="/sewer-line-repair">repair</a>.</p>']);

    $preview = app(PostPreview::class)->for($target->fresh());

    expect($preview['image'])->not->toBeNull()
        ->and($preview['seo']['title'])->toBe('Sewer Line Repair | Guide')
        ->and($preview['seo']['meta_description'])->toBe('How sewer line repair works.')
        ->and(collect($preview['outbound_links'])->pluck('href'))->toContain('/sewer-camera-inspection')
        ->and(collect($preview['inbound_links'])->pluck('slug'))->toContain('sewer-backups');
});

it('the Preview page renders read-only and guards a foreign post', function () {
    Filament::setCurrentPanel('console');

    // A site-scoped admin (not a cross-tenant operator) so the tenant guard actually bites.
    $account = Account::factory()->create();
    $site = Site::factory()->create(['account_id' => $account->id]);
    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $site->id, 'role' => 'site_admin']);
    $this->actingAs($siteAdmin);

    $post = apprCard($site, ['title' => 'Frozen pipe prevention']);

    Livewire::test(BlogPreview::class)->set('siteId', $site->id)->set('content', $post->id)
        ->assertOk()
        ->assertSee('Frozen pipe prevention');

    // A post outside the admin's own site resolves to no preview.
    $foreign = apprCard(Site::factory()->create(), ['title' => 'Someone else’s post']);
    Livewire::test(BlogPreview::class)->set('siteId', $site->id)->set('content', $foreign->id)
        ->assertOk()
        ->assertDontSee('Someone else’s post');
});
