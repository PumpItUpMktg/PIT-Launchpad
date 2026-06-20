<?php

use App\Branding\BrandStudio;
use App\Branding\GeneratedBrand;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Filament\Pages\Guided\Territory;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

test('Brand generate + push sets brand_pushed and Continue advances to Territory', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true,
    ]);

    $studio = Mockery::mock(BrandStudio::class);
    $studio->shouldReceive('generate')->andReturn(new GeneratedBrand(['primary' => '#0E6B6B', 'accent' => '#A6CFCD', 'text' => '#172A2F'], ['heading' => 'Archivo', 'body' => 'Inter'], 'ok'));
    $studio->shouldReceive('save');
    $studio->shouldReceive('push')->andReturn(['updated' => true]);
    app()->instance(BrandStudio::class, $studio);

    Livewire::test(Brand::class)->call('generate')->call('pushBrand');

    expect(SetupState::query()->where('site_id', $this->site->id)->value('brand_pushed'))->toBe(true);

    Livewire::test(Brand::class)->call('proceed')->assertRedirect(Territory::getUrl());
});

test('Brand is gated until WordPress is prepped — the brand push cannot run first', function () {
    // services done but WordPress not prepped (deps_ready false)
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => false,
    ]);

    Livewire::test(Brand::class)->assertRedirect(ConnectWordpress::getUrl());
});
