<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
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
