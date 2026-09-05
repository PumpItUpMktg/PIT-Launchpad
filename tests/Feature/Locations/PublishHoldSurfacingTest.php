<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('surfaces publish-hold as a badge on the locations list — "Held · N live" vs "Publishable"', function () {
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    $held = Location::factory()->create(['site_id' => $site->id, 'name' => 'Fallston', 'publish_held' => true]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'parent_location_id' => $held->id, 'status' => ContentStatus::Published, 'wp_post_id' => 7,
    ]);
    Location::factory()->released()->create(['site_id' => $site->id, 'name' => 'Reviewed Town']);

    Livewire::test(ListLocations::class)
        ->assertOk()
        ->assertSee('Held · 1 live') // the held location with a page still live
        ->assertSee('Publishable');  // the released one
});

it('holds and releases a location from the Towns (LocationsSetup) surface', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->released()->create(['site_id' => $site->id]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('togglePublishHold', $loc->id);
    expect($loc->fresh()->publish_held)->toBeTrue();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('togglePublishHold', $loc->id);
    expect($loc->fresh()->publish_held)->toBeFalse();
});

it('never toggles a location outside the working tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $foreign = Location::factory()->released()->create(['site_id' => $b->id]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $a->id)
        ->call('togglePublishHold', $foreign->id);

    expect($foreign->fresh()->publish_held)->toBeFalse(); // untouched — belongs to another tenant
});
