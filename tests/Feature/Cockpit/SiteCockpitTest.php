<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteCockpit;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

test('the cockpit scopes its metrics to a single tenant', function () {
    $a = Site::factory()->create(['brand_name' => 'Alpha']);
    $b = Site::factory()->create(['brand_name' => 'Beta']);
    Content::factory()->count(2)->create(['site_id' => $a->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $b->id, 'status' => ContentStatus::NeedsReview]);

    app(ActiveTenant::class)->set($a->id);
    expect(Livewire::test(SiteCockpit::class)->assertOk()->instance()->stats['needs_review'])->toBe(2);

    app(ActiveTenant::class)->set($b->id);
    expect(Livewire::test(SiteCockpit::class)->instance()->stats['needs_review'])->toBe(1);
});

test('the cockpit reads the locked tenant and renders it', function () {
    $site = Site::factory()->create(['brand_name' => 'Gamma Plumbing']);

    app(ActiveTenant::class)->set($site->id);

    Livewire::test(SiteCockpit::class)
        ->assertOk()
        ->assertSee('Gamma Plumbing')
        ->assertSee('Per-site cockpit');
});
