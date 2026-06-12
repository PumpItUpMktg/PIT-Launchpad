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

test('the §7a onboarding page is hidden from the operator nav (parked until unify-onboarding)', function () {
    // The hardened Create Site wizard is the canonical create path; the 9-step
    // intake stays mountable (logic intact) but is not a walkable nav door.
    expect(Onboarding::shouldRegisterNavigation())->toBeFalse();
});
