<?php

use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsReport;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    app(ActiveTenant::class)->set($this->site->id); // the lock also binds CurrentSite
    $this->location = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Bedminster']);
    LocationNapProfile::factory()->create(['site_id' => $this->site->id, 'location_id' => $this->location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['name' => 'BBB', 'scope' => DirectoryScope::National]);
    CitationStatus::factory()->create([
        'site_id' => $this->site->id, 'location_id' => $this->location->id, 'directory_id' => $dir->id,
        'presence' => CitationPresence::PresentMatch,
    ]);
});

test('the report renders the client-readable headline for a location', function (): void {
    Livewire::test(CitationsReport::class, ['location' => $this->location->id])
        ->assertOk()
        ->assertSee('Where your business is listed')
        ->assertSee('Bedminster')
        ->assertSee('Listed correctly');
});
