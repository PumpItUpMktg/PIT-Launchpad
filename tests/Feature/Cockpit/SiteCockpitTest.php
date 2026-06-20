<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteCockpit;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
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

    session(['cockpit_site_id' => $a->id]);
    expect(Livewire::test(SiteCockpit::class)->assertOk()->instance()->stats['needs_review'])->toBe(2);

    session(['cockpit_site_id' => $b->id]);
    expect(Livewire::test(SiteCockpit::class)->instance()->stats['needs_review'])->toBe(1);
});

test('the cockpit reads the site from the ?site= param and renders it', function () {
    $site = Site::factory()->create(['brand_name' => 'Gamma Plumbing']);

    Livewire::withQueryParams(['site' => $site->id])
        ->test(SiteCockpit::class)
        ->assertOk()
        ->assertSee('Gamma Plumbing')
        ->assertSee('Per-site cockpit');
});
