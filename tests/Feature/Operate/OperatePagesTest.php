<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperatePages;
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
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    app(ActiveTenant::class)->set($site->id);
});

/** Invoke the protected family() for a given tab on a bare instance. */
function pagesFamily(string $tab): string
{
    $page = new OperatePages;
    $page->tab = $tab;

    return (new ReflectionMethod(OperatePages::class, 'family'))->invoke($page);
}

it('renders one consolidated board with the three family tabs, defaulting to core', function () {
    Livewire::test(OperatePages::class)
        ->assertOk()
        ->assertSet('tab', 'core')
        ->assertSee('Core')
        ->assertSee('Service')
        ->assertSee('Town');
});

it('maps each tab to its PagesBoard family — town is the location family', function () {
    expect(OperatePages::FAMILIES)->toBe(['core' => 'core', 'service' => 'services', 'town' => 'locations'])
        ->and(pagesFamily('core'))->toBe('core')
        ->and(pagesFamily('service'))->toBe('services')
        ->and(pagesFamily('town'))->toBe('locations');
});

it('switches tab via setTab and ignores an unknown tab', function () {
    $page = new OperatePages;
    $page->tab = 'core';

    $page->setTab('town');
    expect($page->tab)->toBe('town');

    $page->setTab('nonsense'); // ignored — stays on the last valid tab
    expect($page->tab)->toBe('town');
});

it('defaults an unknown ?tab= to core on mount', function () {
    Livewire::test(OperatePages::class, ['tab' => 'nonsense'])->assertSet('tab', 'core');
    Livewire::test(OperatePages::class, ['tab' => 'service'])->assertSet('tab', 'service');
});

it('reads a candidate PAGE as "Planned" in the build-state label; posts and other states unchanged', function () {
    $site = app(ActiveTenant::class)->site();

    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Candidate, 'slot_payload' => null,
    ]);
    expect($page->buildStateLabel())->toBe('Planned');

    // A candidate POST is not a planned page build — it keeps the action-oriented label.
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Candidate, 'slot_payload' => null,
    ]);
    expect($post->buildStateLabel())->toBe('Ready to generate');

    // A drafted/approved page is untouched by the Planned wording.
    $approved = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Approved, 'slot_payload' => ['hero' => ['x']],
    ]);
    expect($approved->buildStateLabel())->toBe('Approved · ready to publish');
});
