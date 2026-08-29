<?php

use App\Enums\CitationPresence;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsWorkspace;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    $this->location = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Bedminster']);
    LocationNapProfile::factory()->create(['site_id' => $this->site->id, 'location_id' => $this->location->id, 'categories' => null]);
    $this->dir = Directory::factory()->create(['name' => 'Yelp', 'domain' => 'yelp.com', 'is_submittable' => true]);
    $this->status = CitationStatus::factory()->create([
        'site_id' => $this->site->id, 'location_id' => $this->location->id, 'directory_id' => $this->dir->id,
        'presence' => CitationPresence::Absent,
    ]);
});

test('the workspace renders the directory rows for a location', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->assertOk()
        ->assertSee('Yelp');
});

test('create work orders records issuance on the selected citations', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('createWorkOrders', [$this->status->id]);

    expect($this->status->refresh()->work_order_count)->toBe(1);
});

test('mark not relevant writes a tenant exclusion for the whole tenant', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('markNotRelevant', $this->dir->id);

    expect(TenantDirectoryExclusion::query()->where('site_id', $this->site->id)->where('directory_id', $this->dir->id)->exists())->toBeTrue();
});
