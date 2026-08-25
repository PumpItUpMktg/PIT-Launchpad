<?php

use App\Enums\UserRole;
use App\Filament\Resources\GeoPromptResource\Pages\ListGeoPrompts;
use App\Jobs\BridgeSiteGeoGaps;
use App\Jobs\SeedSiteGeoPrompts;
use App\Jobs\SyncSiteGeo;
use App\Jobs\TopUpSiteGeoPrompts;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('lists GEO prompts with their latest result for an operator', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    $prompt = GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'best sump pump repair in Union NJ', 'active' => true]);

    Livewire::test(ListGeoPrompts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$prompt]);
});

it('defaults the tenant filter to the operator\'s session working site', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $promptA = GeoPrompt::create(['site_id' => $a->id, 'prompt' => 'a', 'active' => true]);
    $promptB = GeoPrompt::create(['site_id' => $b->id, 'prompt' => 'b', 'active' => true]);
    session(['guided_site_id' => $a->id]);

    // The page opens scoped to the working tenant — B's prompts are filtered out until the operator changes it.
    Livewire::test(ListGeoPrompts::class)
        ->assertCanSeeTableRecords([$promptA])
        ->assertCanNotSeeTableRecords([$promptB]);
});

it('queues an auto-seed per site that has services', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $a = Site::factory()->create();
    Site::factory()->create(); // no services → not seeded
    Service::factory()->create(['site_id' => $a->id]);

    Livewire::test(ListGeoPrompts::class)->callAction('seed');

    Queue::assertPushed(SeedSiteGeoPrompts::class, 1);
});

it('queues a weakness top-up per site that has snapshots', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $a = Site::factory()->create();
    Site::factory()->create(); // no snapshots → not topped up
    $p = GeoPrompt::create(['site_id' => $a->id, 'prompt' => 'q', 'active' => true]);
    GeoSnapshot::create(['site_id' => $a->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);

    Livewire::test(ListGeoPrompts::class)->callAction('topup');

    Queue::assertPushed(TopUpSiteGeoPrompts::class, 1);
});

it('queues a gap→content bridge per site that has snapshots', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $a = Site::factory()->create();
    Site::factory()->create(); // no snapshots → not bridged
    $p = GeoPrompt::create(['site_id' => $a->id, 'prompt' => 'q', 'active' => true]);
    GeoSnapshot::create(['site_id' => $a->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);

    Livewire::test(ListGeoPrompts::class)->callAction('bridge');

    Queue::assertPushed(BridgeSiteGeoGaps::class, 1);
});

it('queues one GEO check per site that has active prompts', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    GeoPrompt::create(['site_id' => $a->id, 'prompt' => 'p', 'active' => true]);
    GeoPrompt::create(['site_id' => $b->id, 'prompt' => 'q', 'active' => true]);
    GeoPrompt::create(['site_id' => $b->id, 'prompt' => 'r', 'active' => false]);

    Livewire::test(ListGeoPrompts::class)->callAction('run');

    Queue::assertPushed(SyncSiteGeo::class, 2);   // a + b (distinct sites with an active prompt)
});
