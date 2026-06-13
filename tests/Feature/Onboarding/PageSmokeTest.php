<?php

use App\Filament\Pages\Onboarding;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the §7a onboarding page is disabled — nav hidden AND route blocked (parked until unify-onboarding)', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());

    // It writes broken (app_password-only, unverified, compromised) connections, so
    // the door is closed at both the nav and the route until the unify slice ports
    // the hardened connection. The form/submit logic is left intact for that slice.
    expect(Onboarding::canAccess())->toBeFalse()
        ->and(Onboarding::shouldRegisterNavigation())->toBeFalse();

    Livewire::test(Onboarding::class)->assertForbidden(); // direct URL 403s
});
