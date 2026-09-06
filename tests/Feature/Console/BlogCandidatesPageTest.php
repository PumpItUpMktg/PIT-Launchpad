<?php

use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogCandidates;
use App\Jobs\GeneratePost;
use App\Models\Account;
use App\Models\Content;
use App\Models\Membership;
use App\Models\Silo;
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

it('groups candidates by silo (local-first) on the board', function () {
    $super = User::factory()->create();
    $this->actingAs($super);
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);

    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'General story', 'matched_silo_id' => $silo->id, 'meta' => ['scope' => 'general']]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Local story', 'matched_silo_id' => $silo->id, 'meta' => ['scope' => 'local']]);

    $page = new BlogCandidates;
    $page->siteId = $site->id;

    $groups = $page->getCandidateGroupsProperty();
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['silo'])->toBe('Sump Pumps')
        ->and($groups[0]['total'])->toBe(2)
        ->and($groups[0]['local'])->toBe(1)
        ->and($groups[0]['visible'][0]['title'])->toBe('Local story'); // local first
});

it('adds a hand-typed manual candidate to the board', function () {
    $super = User::factory()->create();
    $this->actingAs($super);
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $page = new BlogCandidates;
    $page->siteId = $site->id;
    $page->manualTitle = 'Polk high-water-table job writeup';
    $page->manualSiloId = $silo->id;
    $page->manualTown = 'Polk';
    $page->addManual();

    $c = Content::withoutGlobalScopes()->where('site_id', $site->id)->where('source_name', 'manual')->first();
    expect($c)->not->toBeNull()
        ->and($c->status)->toBe(ContentStatus::Candidate)
        ->and($c->meta['scope'])->toBe('local')
        ->and($c->meta['shelf_life'])->toBe('topical');

    // Form reset, and it shows on the board.
    expect($page->manualTitle)->toBe('')
        ->and(collect($page->getCandidatesProperty())->pluck('id'))->toContain($c->id);
});

it('will not add a manual candidate under a silo outside the active site', function () {
    $super = User::factory()->create();
    $this->actingAs($super);
    $site = Site::factory()->create();
    $foreignSilo = Silo::factory()->create(['site_id' => Site::factory()->create()->id]);

    $page = new BlogCandidates;
    $page->siteId = $site->id;
    $page->manualTitle = 'Cross-tenant attempt';
    $page->manualSiloId = $foreignSilo->id;
    $page->addManual();

    expect(Content::withoutGlobalScopes()->where('source_name', 'manual')->count())->toBe(0);
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
