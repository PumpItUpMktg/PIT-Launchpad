<?php

use App\Enums\UserRole;
use App\Filament\Resources\KeywordResource\Pages\ListKeywords;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('shows the grid column and toggles a single keyword onto the grid', function () {
    $site = Site::factory()->create();
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => false]);

    Livewire::test(ListKeywords::class)
        ->assertOk()
        ->callTableAction('toggleGrid', $kw);

    expect($kw->refresh()->is_grid_keyword)->toBeTrue();

    // The same action flips it back off.
    Livewire::test(ListKeywords::class)->callTableAction('toggleGrid', $kw);
    expect($kw->refresh()->is_grid_keyword)->toBeFalse();
});

it('bulk adds and removes keywords from the grid across a selection', function () {
    $site = Site::factory()->create();
    $a = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'a', 'is_grid_keyword' => false]);
    $b = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'b', 'is_grid_keyword' => false]);

    Livewire::test(ListKeywords::class)
        ->callTableBulkAction('addToGrid', [$a, $b]);

    expect($a->refresh()->is_grid_keyword)->toBeTrue()
        ->and($b->refresh()->is_grid_keyword)->toBeTrue();

    Livewire::test(ListKeywords::class)
        ->callTableBulkAction('removeFromGrid', [$a, $b]);

    expect($a->refresh()->is_grid_keyword)->toBeFalse()
        ->and($b->refresh()->is_grid_keyword)->toBeFalse();
});

it('filters the list by grid membership', function () {
    $site = Site::factory()->create();
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'on-grid-kw', 'is_grid_keyword' => true]);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'off-grid-kw', 'is_grid_keyword' => false]);

    Livewire::test(ListKeywords::class)
        ->filterTable('is_grid_keyword', 1)
        ->assertCanSeeTableRecords(Keyword::where('query', 'on-grid-kw')->get())
        ->assertCanNotSeeTableRecords(Keyword::where('query', 'off-grid-kw')->get());
});
