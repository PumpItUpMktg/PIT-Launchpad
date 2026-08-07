<?php

use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogCandidates;
use App\Jobs\GeneratePost;
use App\Models\Account;
use App\Models\Content;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function ccCandidate(Site $site, string $title = 'Spring sump maintenance'): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Candidate,
        'title' => $title,
    ]);
}

it('lists candidates for the active site and generates one', function () {
    Queue::fake();
    $super = User::factory()->create(); // Operator = Super Admin
    $this->actingAs($super);
    $site = Site::factory()->create();
    $candidate = ccCandidate($site);

    $page = new BlogCandidates;
    $page->siteId = $site->id;

    expect(collect($page->getCandidatesProperty())->pluck('id'))->toContain($candidate->id);

    $page->promote($candidate->id);

    Queue::assertPushed(GeneratePost::class, fn (GeneratePost $job): bool => $job->contentId === $candidate->id);
});

it('dismisses a candidate out of the funnel', function () {
    $super = User::factory()->create();
    $this->actingAs($super);
    $site = Site::factory()->create();
    $candidate = ccCandidate($site);

    $page = new BlogCandidates;
    $page->siteId = $site->id;
    $page->dismiss($candidate->id);

    expect($candidate->fresh()->status)->toBe(ContentStatus::Rejected);
});

it('will not act on a candidate outside the Site Admin\'s own site', function () {
    Queue::fake();
    $account = Account::factory()->create();
    $mine = Site::factory()->create(['account_id' => $account->id]);
    $foreign = Site::factory()->create();

    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $mine->id, 'role' => 'site_admin']);
    $this->actingAs($siteAdmin);

    $candidate = ccCandidate($foreign, 'Not mine');

    $page = new BlogCandidates;
    $page->siteId = $mine->id;
    $page->promote($candidate->id); // foreign id — must be a no-op
    $page->dismiss($candidate->id);

    Queue::assertNothingPushed();
    expect($candidate->fresh()->status)->toBe(ContentStatus::Candidate);
});
