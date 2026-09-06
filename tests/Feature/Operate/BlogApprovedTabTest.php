<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => CurrentSite::clear());

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    app(ActiveTenant::class)->set($this->site->id);
});

it('offers an Approved tab in the Posts pipeline', function () {
    Livewire::test(OperateBlog::class)
        ->assertOk()
        ->assertSee('Candidates')
        ->assertSee('Review')
        ->assertSee('Approved')
        ->assertSee('Published')
        ->call('setTab', 'approved')->assertSet('tab', 'approved')
        ->call('setTab', 'nonsense')->assertSet('tab', 'approved'); // unknown ignored
});

it('the Approved tab lists approved posts queued to publish, not published ones', function () {
    $approved = Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved, 'title' => 'Queued post',
    ]);
    Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Published, 'title' => 'Live post', 'published_at' => now(), 'wp_post_id' => 9,
    ]);

    $rows = Livewire::test(OperateBlog::class)->set('tab', 'approved')->instance()->approved;

    expect(collect($rows)->pluck('id')->all())->toBe([(string) $approved->id])
        ->and(collect($rows)->pluck('title')->all())->toBe(['Queued post']);
});

it('a released post leaves the Approved (QA) tab and moves to the Publish queue', function () {
    $waiting = Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved, 'title' => 'Still in QA',
    ]);
    $released = Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved, 'title' => 'Sent to publish',
    ]);
    $released->releaseToPublish();

    $board = app(BlogBoard::class);

    // The Approved tab is the un-released QA queue; the Publish queue is the released half.
    expect(collect($board->approved($this->site->id))->pluck('id')->all())->toBe([(string) $waiting->id])
        ->and(collect($board->publishing($this->site->id))->pluck('id')->all())->toBe([(string) $released->id]);
});

it('an old un-released approved post is NOT flagged stalled; only a released, stuck one is', function () {
    $waiting = Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved, 'title' => 'Waiting in QA',
    ]);
    $stuck = Content::factory()->create([
        'site_id' => $this->site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved, 'title' => 'Released but stuck',
    ]);
    $stuck->releaseToPublish();

    // Both are older than the stall window; only the RELEASED one is genuinely stalled (the un-released
    // one is simply waiting on the operator — the bug was mislabelling it "stalled — worker down").
    Content::withoutGlobalScopes()->whereKey([$waiting->id, $stuck->id])
        ->update(['updated_at' => now()->subSeconds(BlogBoard::STALLED_AFTER_SECONDS + 60)]);

    $board = app(BlogBoard::class);
    $waitingCard = collect($board->approved($this->site->id))->firstWhere('id', (string) $waiting->id);
    $stuckCard = collect($board->publishing($this->site->id))->firstWhere('id', (string) $stuck->id);

    expect($waitingCard['stalled'])->toBeFalse()
        ->and($stuckCard['stalled'])->toBeTrue();
});
