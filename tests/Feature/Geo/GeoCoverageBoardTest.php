<?php

use App\Enums\GeoIntent;
use App\Enums\UserRole;
use App\Filament\Pages\GeoCoverageBoard;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('renders the GEO coverage board with the matrix + gap list for an operator', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]);

    $cited = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'intent' => GeoIntent::Hire->value, 'prompt' => 'best repair in union', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $cited->id, 'engine' => 'claude', 'cited' => true, 'checked_at' => now()]);

    $absent = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'intent' => GeoIntent::Cost->value, 'prompt' => 'repair cost in union', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $absent->id, 'engine' => 'claude', 'cited' => false, 'competitors' => ['Rival Plumbing'], 'checked_at' => now()]);

    Livewire::test(GeoCoverageBoard::class)
        ->assertOk()
        ->assertSee('Union')            // town column
        ->assertSee('Repair')           // service row
        ->assertSee('50%')              // the Repair × Union matrix cell (1 of 2 prompts cited)
        ->assertSee("Where you're absent", escape: false)
        ->assertSee('Rival Plumbing');  // the competitor cited on the absent gap
});
