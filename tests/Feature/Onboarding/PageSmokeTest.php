<?php

use App\Filament\Pages\Onboarding;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the onboarding wizard page mounts and renders', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());

    Livewire::test(Onboarding::class)->assertOk();
});
