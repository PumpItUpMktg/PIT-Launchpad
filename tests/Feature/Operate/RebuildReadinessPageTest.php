<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function readinessSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Readiness Co']);
    session(['guided_site_id' => $site->id]);

    return $site;
}

it('shows the readiness checklist for the working tenant', function () {
    $site = readinessSite();
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sewer', 'wp_category_id' => null]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'A post', 'silo_id' => null,
    ]);

    Livewire::test(RebuildReadiness::class)
        ->assertOk()
        ->assertSee('Blog routing')
        ->assertSee('re-route')            // the red row names its fix
        ->assertSee('Categories');
});

it('runs the reconcile cascade from the page and queues the affected republish', function () {
    Queue::fake();
    $site = readinessSite();
    Silo::factory()->create([
        'site_id' => $site->id, 'name' => 'Sewer',
        'rule_set' => ['include_patterns' => ['sewer'], 'seed_terms' => ['sewer'], 'exclude_patterns' => []],
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford Sewer', 'body' => 'Sewer work in Cranford.', 'silo_id' => null, 'matched_silo_id' => null,
    ]);

    Livewire::test(RebuildReadiness::class)->call('reconcile', false);

    Queue::assertPushed(PublishContent::class);
});

it('exposes the Readiness action on the Portfolio', function () {
    Site::factory()->create();
    Livewire::test(ListSites::class)->assertTableActionExists('readiness');
});
