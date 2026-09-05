<?php

use App\Enums\ScanCadence;
use App\Enums\UserRole;
use App\Filament\Resources\CoverageScanPlanResource\Pages\CreateCoverageScanPlan;
use App\Filament\Resources\CoverageScanPlanResource\Pages\EditCoverageScanPlan;
use App\Filament\Resources\CoverageScanPlanResource\Pages\ListCoverageScanPlans;
use App\Jobs\SeedSiteCoverage;
use App\Models\CoverageArea;
use App\Models\CoverageScanPlan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function planFixture(): CoverageScanPlan
{
    $site = Site::factory()->create(['brand_name' => 'Acme']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'gbp_url' => 'https://g/?cid=1', 'place_id' => 'p', 'lat' => 40.8, 'lng' => -74.2]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Belleville', 'population' => 36000, 'lat' => 40.79, 'lng' => -74.15, 'source_location_ids' => [$loc->id]]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);
    app(ActiveTenant::class)->set($site->id); // the resource is tenant-locked; the plan belongs to this tenant

    return CoverageScanPlan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => [$kw->id]]);
}

it('lists coverage scan plans with the resolved location + est cost', function () {
    $plan = planFixture();

    // No Tenant column any more (tenant-lock remediation) — the board is the locked tenant's plans.
    Livewire::test(ListCoverageScanPlans::class)
        ->assertOk()
        ->assertSee('Montclair');
});

it('renders the create and edit forms', function () {
    $plan = planFixture();

    Livewire::test(CreateCoverageScanPlan::class)->assertOk();
    Livewire::test(EditCoverageScanPlan::class, ['record' => $plan->getRouteKey()])->assertOk();
});

it('queues county-coverage seeding from the Seed county towns action', function () {
    Queue::fake();
    $plan = planFixture();

    Livewire::test(ListCoverageScanPlans::class)->callTableAction('seedCounty', $plan);

    Queue::assertPushed(SeedSiteCoverage::class, fn ($job): bool => $job->siteId === $plan->site_id);
});
