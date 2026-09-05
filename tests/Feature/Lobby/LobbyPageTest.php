<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Filament\Pages\Lobby;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Resources\ConnectionsResource;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(fn () => CurrentSite::clear());

it('renders the lobby with a card per tenant, brand + domain + site id', function () {
    $gurus = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://gurus.example', 'status' => SiteStatus::Active]);
    Site::factory()->create(['brand_name' => 'Sump Pump Today', 'domain_url' => 'https://today.example', 'status' => SiteStatus::Active]);

    Livewire::test(Lobby::class)
        ->assertOk()
        ->assertSee('Sump Pump Gurus')
        ->assertSee('gurus.example')
        ->assertSee('Sump Pump Today')   // near-identical brands disambiguated by domain
        ->assertSee($gurus->id);         // the card shows the site id (for the CLI / support)
});

it('is reachable with NO tenant selected — the gate allowlists it (acceptance 5 companion)', function () {
    // Two sites → a multi-site operator is NOT auto-selected into one, so no tenant is locked.
    Site::factory()->count(2)->create(['status' => SiteStatus::Active]);

    $this->get(Lobby::getUrl())->assertOk();
});

it('clears any locked tenant on arrival — the lobby is the no-tenant surface', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    app(ActiveTenant::class)->set($site->id);
    expect(app(ActiveTenant::class)->id())->toBe($site->id);

    Livewire::test(Lobby::class)->assertOk();

    expect(app(ActiveTenant::class)->id())->toBeNull();
});

it('entering a card locks the tenant and opens its dashboard, in one action (acceptance 14)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);

    Livewire::test(Lobby::class)
        ->call('enter', $site->id)
        ->assertRedirect(TenantDashboard::getUrl());

    expect(app(ActiveTenant::class)->id())->toBe($site->id); // locked in the same action
});

it('clicking a badge locks the tenant and opens the badge\'s filtered surface (acceptance 14)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);

    // A mapped badge → its surface.
    Livewire::test(Lobby::class)
        ->call('enterBadge', $site->id, 'wp_connection')
        ->assertRedirect(ConnectionsResource::getUrl('index'));
    expect(app(ActiveTenant::class)->id())->toBe($site->id);

    // Another mapped badge → the citations board.
    Livewire::test(Lobby::class)
        ->call('enterBadge', $site->id, 'wrong_nap')
        ->assertRedirect(CitationsBoard::getUrl());

    // An unmapped/not-yet-built target falls back to the dashboard — still one click into the locked tenant.
    Livewire::test(Lobby::class)
        ->call('enterBadge', $site->id, 'held_market')
        ->assertRedirect(TenantDashboard::getUrl());
});

it('search and filter are server-side and reactive', function () {
    // Alpha is genuinely clean: setup-complete (service + active voice + a live WP connection), so the
    // tier-2 setup_gaps badge never fires and it stays out of "needs attention".
    $alpha = Site::factory()->create(['brand_name' => 'Alpha Plumbing', 'status' => SiteStatus::Active]);
    Service::factory()->create(['site_id' => $alpha->id]);
    VoiceProfile::factory()->create(['site_id' => $alpha->id, 'status' => VoiceStatus::Active]);
    Connection::factory()->create(['site_id' => $alpha->id, 'provider' => ConnectionProvider::WpAppPassword, 'compromised' => false]);
    $beta = Site::factory()->create(['brand_name' => 'Beta Rooter', 'status' => SiteStatus::Active]);
    Content::factory()->create(['site_id' => $beta->id, 'status' => ContentStatus::RenderFailed]); // Beta needs attention

    Livewire::test(Lobby::class)
        ->set('search', 'Beta')
        ->assertSee('Beta Rooter')
        ->assertDontSee('Alpha Plumbing')
        ->set('search', '')
        ->set('filter', 'attention')
        ->assertSee('Beta Rooter')      // has a Tier-1 condition
        ->assertDontSee('Alpha Plumbing'); // clean → filtered out of "needs attention"
});
