<?php

use App\Enums\ConnectionProvider;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Models\Connection;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

it('re-pushes the chrome with the corporate NAP when the Business step saves on a connected site', function () {
    Http::fake(['*/wp-json/launchpad/v1/site-profile' => Http::response(['updated' => true], 200)]);

    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);
    // A physical location (Montclair) — what the chrome USED to fall back to.
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'phone' => '(973) 555-0100', 'address' => '5 Bloomfield Ave, Montclair, NJ']);
    session(['guided_site_id' => $site->id]);

    Livewire::test(BusinessStep::class)
        ->set('phone', '(877) 786-7834')
        ->set('corporateStreet', '377 Valley Road')
        ->set('corporateCity', 'Clifton')
        ->set('corporateState', 'NJ')
        ->set('corporatePostalCode', '07013')
        ->call('save');

    // The pushed chrome carries the CORPORATE NAP, not the Montclair location's.
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/site-profile')
        && $r['phone'] === '(877) 786-7834'
        && str_contains((string) $r['address'], '377 Valley Road')
        && ! str_contains((string) $r['address'], 'Montclair'));

    expect($site->fresh()->corporate_city)->toBe('Clifton');
});

it('does not attempt a chrome push for a site with no WordPress connection', function () {
    Http::fake();
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    session(['guided_site_id' => $site->id]);

    Livewire::test(BusinessStep::class)
        ->set('phone', '(877) 786-7834')
        ->set('corporateStreet', '377 Valley Road')
        ->set('corporateCity', 'Clifton')
        ->call('save');

    Http::assertNothingSent();
    expect($site->fresh()->corporate_street)->toBe('377 Valley Road');
});
