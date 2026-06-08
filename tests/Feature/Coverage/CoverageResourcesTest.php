<?php

use App\Enums\UserRole;
use App\Filament\Resources\KeywordResource\Pages\ListKeywords;
use App\Filament\Resources\SiloManagementResource\Pages\ListSilos;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the coverage resources render for an operator', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    Keyword::factory()->create(['site_id' => $site->id]);
    Silo::factory()->create(['site_id' => $site->id]);

    Livewire::test(ListKeywords::class)->assertOk();
    Livewire::test(ListSilos::class)->assertOk();
});
