<?php

use App\Filament\Console\Pages\ConsoleHome;
use App\Models\User;
use Filament\Facades\Filament;

it('ships one prefix-based interaction sheet covering every custom control family', function () {
    $css = view('filament.shared.interaction-styles')->render();

    expect($css)
        ->toContain('data-lp-interactions')
        // Prefix-based, so any *-btn / *-tab / *-chip family is covered without enumeration.
        ->toContain('[class*="-btn"]')
        ->toContain('[class*="-tab"]')
        ->toContain('[class*="-chip"]')
        // The four feedback states a user needs.
        ->toContain(':hover')
        ->toContain(':active')
        ->toContain(':focus-visible')
        ->toContain(':disabled')
        // Zero-specificity so it fills gaps without overriding a page's own hover.
        ->toContain(':where(')
        // Native Filament controls are left alone.
        ->toContain(':not([class*="fi-"])')
        // Accessibility: honor reduced-motion.
        ->toContain('prefers-reduced-motion')
        // Click confirmation: a JS-driven pulse so the user always knows the click landed.
        ->toContain('@keyframes lp-click-pulse')
        ->toContain('.lp-clicked')
        ->toContain("addEventListener('click'");
});

it('injects the interaction sheet into a rendered panel', function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->create()); // Super Admin (Operator)

    $this->get(ConsoleHome::getUrl())
        ->assertOk()
        ->assertSee('data-lp-interactions', escape: false);
});
