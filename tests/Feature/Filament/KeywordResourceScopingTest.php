<?php

use App\Enums\UserRole;
use App\Filament\Resources\KeywordResource\Pages\ListKeywords;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('opens Targets & gaps scoped to the operator working tenant, not every tenant', function () {
    $mine = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $other = Site::factory()->create(['brand_name' => 'Other Tenant']);
    $mineKw = Keyword::factory()->create(['site_id' => $mine->id, 'query' => 'sump pump installation']);
    $otherKw = Keyword::factory()->create(['site_id' => $other->id, 'query' => 'water heater repair']);

    app(ActiveTenant::class)->set($mine->id);      // the lock (→ SiteScope) scopes the board to $mine

    Livewire::test(ListKeywords::class)
        ->assertCanSeeTableRecords([$mineKw])
        ->assertCanNotSeeTableRecords([$otherKw]);
});

// REMOVED (tenant-lock remediation, rule 3): "the Tenant filter is still switchable to another tenant"
// asserted the all-tenant SelectFilter that let an operator view another tenant's keywords under a lock —
// the shape-A breach itself. The filter is gone; changing tenant is Exit site → Lobby → enter.
