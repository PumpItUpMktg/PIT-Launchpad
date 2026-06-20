<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use App\Onboarding\WordpressPrep;
use App\Operator\Controls\WordpressConnector;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 2, 'services_done' => true]);

    // Fake the WP-prep seam (no live WordPress in tests); $ok toggles the result.
    $this->prep = new class(app(WordpressConnector::class)) extends WordpressPrep
    {
        public bool $ok = true;

        public function prep(Site $site, array $input): array
        {
            return ['ready' => $this->ok, 'steps' => [], 'error' => $this->ok ? null : 'bad creds'];
        }

        public function status(Site $site): array
        {
            return ['Connected & verified' => false];
        }
    };
    app()->instance(WordpressPrep::class, $this->prep);
});

test('connect & prep sets deps_ready and Continue advances to Brand', function () {
    Livewire::test(ConnectWordpress::class)
        ->set('baseUrl', 'https://example.com')
        ->set('username', 'admin')
        ->set('appPassword', 'abcd efgh ijkl')
        ->call('connectAndPrep')
        ->assertSet('appPassword', ''); // secret cleared from component state

    expect(SetupState::query()->where('site_id', $this->site->id)->value('deps_ready'))->toBe(true);

    Livewire::test(ConnectWordpress::class)->call('proceed')->assertRedirect(Brand::getUrl());
});

test('Continue is blocked until WordPress is connected & prepped', function () {
    Livewire::test(ConnectWordpress::class)->call('proceed')->assertNoRedirect();

    expect(SetupState::query()->where('site_id', $this->site->id)->value('deps_ready'))->toBe(false);
});

test('a failed connect leaves deps_ready false', function () {
    $this->prep->ok = false;

    Livewire::test(ConnectWordpress::class)
        ->set('baseUrl', 'https://example.com')->set('username', 'admin')->set('appPassword', 'x')
        ->call('connectAndPrep');

    expect(SetupState::query()->where('site_id', $this->site->id)->value('deps_ready'))->toBe(false);
});
