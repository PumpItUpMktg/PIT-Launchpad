<?php

use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogPublish;
use App\Filament\Console\Pages\BlogReview;
use App\Jobs\PublishContent;
use App\Models\Account;
use App\Models\Content;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function ccDraft(Site $site, ContentStatus $status): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'status' => $status,
        'title' => 'Sump pump buying guide',
        'body' => '<p>A real drafted body.</p>',
    ]);
}

it('approves a review draft into ready-to-publish without pushing to WordPress', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $draft = ccDraft($site, ContentStatus::NeedsReview);

    $page = new BlogReview;
    $page->siteId = $site->id;

    expect(collect($page->getReviewProperty())->pluck('id'))->toContain($draft->id);

    $page->approve($draft->id);

    expect($draft->fresh()->status)->toBe(ContentStatus::Approved);
    Queue::assertNotPushed(PublishContent::class); // approve never publishes here
});

it('rejects a review draft with a reason', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $draft = ccDraft($site, ContentStatus::NeedsReview);

    $page = new BlogReview;
    $page->siteId = $site->id;
    $page->startReject($draft->id);
    $page->rejectReason = 'Off brand';
    $page->reject();

    expect($draft->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($draft->fresh()->reject_reason)->toBe('Off brand')
        ->and($page->rejectingId)->toBeNull();
});

it('publishes an approved post straight to WordPress (queued)', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $approved = ccDraft($site, ContentStatus::Approved);
    $approved->releaseToPublish(); // released from the Approved page onto the Publish queue

    $page = new BlogPublish;
    $page->siteId = $site->id;

    expect(collect($page->getPublishingProperty())->pluck('id'))->toContain($approved->id);

    $page->publish($approved->id);

    Queue::assertPushed(PublishContent::class, fn (PublishContent $job): bool => $job->contentId === $approved->id);
});

it('will not publish a post outside the Site Admin\'s own site', function () {
    Queue::fake();
    $account = Account::factory()->create();
    $mine = Site::factory()->create(['account_id' => $account->id]);
    $foreign = Site::factory()->create();

    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $mine->id, 'role' => 'site_admin']);
    $this->actingAs($siteAdmin);

    $approved = ccDraft($foreign, ContentStatus::Approved);

    $page = new BlogPublish;
    $page->siteId = $mine->id;
    $page->publish($approved->id);

    Queue::assertNothingPushed();
    expect($approved->fresh()->status)->toBe(ContentStatus::Approved);
});
