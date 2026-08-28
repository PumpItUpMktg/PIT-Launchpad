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

it('opens Targets & gaps scoped to the operator working tenant, not every tenant', function () {
    $mine = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $other = Site::factory()->create(['brand_name' => 'Other Tenant']);
    $mineKw = Keyword::factory()->create(['site_id' => $mine->id, 'query' => 'sump pump installation']);
    $otherKw = Keyword::factory()->create(['site_id' => $other->id, 'query' => 'water heater repair']);

    session(['guided_site_id' => $mine->id]);      // the working tenant WorkingTenant reads

    Livewire::test(ListKeywords::class)
        ->assertCanSeeTableRecords(Keyword::whereKey($mineKw->id)->get())
        ->assertCanNotSeeTableRecords(Keyword::whereKey($otherKw->id)->get());
});

it('the Tenant filter is still switchable to another tenant', function () {
    $mine = Site::factory()->create();
    $other = Site::factory()->create();
    $mineKw = Keyword::factory()->create(['site_id' => $mine->id, 'query' => 'a']);
    $otherKw = Keyword::factory()->create(['site_id' => $other->id, 'query' => 'b']);
    session(['guided_site_id' => $mine->id]);

    Livewire::test(ListKeywords::class)
        ->filterTable('site_id', $other->id)
        ->assertCanSeeTableRecords(Keyword::whereKey($otherKw->id)->get())
        ->assertCanNotSeeTableRecords(Keyword::whereKey($mineKw->id)->get());
});
