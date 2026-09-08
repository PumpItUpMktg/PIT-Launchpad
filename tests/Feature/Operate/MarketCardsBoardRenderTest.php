<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\MarketCardsBoard;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Renders the Markets board end-to-end (Livewire page → markets.blade → the <x-lp.market-card> component),
 * which the read-model tests in MarketCardsTest never exercise. A prior build shipped an inline @if inside
 * the market-card's chip SLOT — a shape Blade's anonymous-component compiler mis-nests into an orphaned
 * `endif`, a hard compile error the moment any card rendered (a 500 for every tenant with ≥1 Location, while
 * the empty demo path stayed clean). These assert the view actually COMPILES and renders, in every card
 * state, so that class of blade regression can't reach prod silently again.
 */
beforeEach(fn () => Filament::setCurrentPanel('admin'));

function mcOperator(): User
{
    return User::factory()->create(['role' => UserRole::Operator]);
}

it('renders a market card for a published (non-held) location without a blade compile error', function () {
    $this->actingAs(mcOperator());
    $site = Site::factory()->create(['domain_url' => 'https://render.test']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Render Market', 'publish_held' => false]);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(MarketCardsBoard::class)->assertOk();
});

it('renders the held-market chip with its drafted-count branch — the exact slot that miscompiled', function () {
    $this->actingAs(mcOperator());
    $site = Site::factory()->create(['domain_url' => 'https://render.test']);
    // Held by default; a drafted (unpublished) hub page makes drafted_pages > 0 so the chip renders
    // "Held — seasoning · 1 drafted" — the branch that lived inside the component slot as an inline @if.
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Held Market']);
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => 'Held Market, PA', 'slug' => 'held-market-pa', 'status' => ContentStatus::Drafted,
        'location_id' => $loc->id, 'version' => 1,
    ]);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(MarketCardsBoard::class)
        ->assertOk()
        ->assertSee('1 drafted');
});

it('renders the empty state when the tenant has no locations', function () {
    $this->actingAs(mcOperator());
    $site = Site::factory()->create(['domain_url' => 'https://render.test']);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(MarketCardsBoard::class)->assertOk();
});
