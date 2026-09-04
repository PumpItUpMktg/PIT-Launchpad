<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateLive;
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
    $this->site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    app(ActiveTenant::class)->set($this->site->id);
});

it('renders the type selector with live counts and defaults to All', function () {
    Content::factory()->create(['site_id' => $this->site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'published_at' => now()]);
    Content::factory()->create(['site_id' => $this->site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'published_at' => now()]);

    Livewire::test(OperateLive::class)
        ->assertOk()
        ->assertSet('tab', 'all')
        ->assertSee('All')
        ->assertSee('Blog')
        ->assertSee('Core')
        ->assertSee('Service')
        ->assertSee('Town');

    expect(Livewire::test(OperateLive::class)->instance()->counts)
        ->toBe(['all' => 2, 'blog' => 1, 'core' => 0, 'service' => 1, 'town' => 0]);
});

it('switches tab via setTab and rejects an unknown tab', function () {
    $board = Livewire::test(OperateLive::class);
    $board->call('setTab', 'town')->assertSet('tab', 'town');
    $board->call('setTab', 'nonsense')->assertSet('tab', 'town');
});

it('scopes rows to the active tab', function () {
    Content::factory()->create(['site_id' => $this->site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'published_at' => now()]);
    $service = Content::factory()->create(['site_id' => $this->site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'published_at' => now()]);

    $rows = Livewire::test(OperateLive::class)->set('tab', 'service')->instance()->rows;
    expect(collect($rows)->pluck('id')->all())->toBe([(string) $service->id]);
});
