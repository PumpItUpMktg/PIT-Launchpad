<?php

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsPortfolio;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function portfolioSite(string $brand, CitationPresence $presence, CitationLifecycleState $lifecycle = CitationLifecycleState::None): void
{
    $site = Site::factory()->create(['brand_name' => $brand]);
    CurrentSite::set($site->id);
    $location = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=1']);
    LocationNapProfile::factory()->create(['site_id' => $site->id, 'location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);
    CitationStatus::factory()->create([
        'site_id' => $site->id, 'location_id' => $location->id, 'directory_id' => $dir->id,
        'presence' => $presence, 'lifecycle' => $lifecycle,
    ]);
}

test('the portfolio lists tenants most-urgent-first', function (): void {
    portfolioSite('Clean Co', CitationPresence::PresentMatch);
    portfolioSite('Stalled Co', CitationPresence::Absent, CitationLifecycleState::Stalled);

    Livewire::test(CitationsPortfolio::class)
        ->assertOk()
        ->assertSeeInOrder(['Stalled Co', 'Clean Co']);
});

test('the operator can seed the directory catalog from the page (no console access)', function (): void {
    expect(Directory::query()->count())->toBe(0);

    Livewire::test(CitationsPortfolio::class)->callAction('seedDirectories');

    expect(Directory::query()->count())->toBeGreaterThanOrEqual(15)
        ->and(Directory::query()->where('domain', 'yelp.com')->exists())->toBeTrue();
});
