<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

/** A backlog that aged past the stall floor with nothing reserved → worker-down. */
function agedJobs(int $n = 2): void
{
    for ($i = 0; $i < $n; $i++) {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => time() - 600, 'created_at' => time() - 600,
        ]);
    }
}

it('shows the worker-down banner with the drain button and the CLI hint', function () {
    Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    agedJobs();

    Livewire::test(OperateServicePages::class)
        ->assertSee('background worker looks down')
        ->assertSee('Publish stuck pages now')
        ->assertSee('launchpad:drain-publish');
});

it('reports nothing to drain when no pages are in flight', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)

    Livewire::test(OperateServicePages::class)
        ->call('drainStuckPages')
        ->assertNotified('Nothing to drain');
});

it('attempts each stuck page synchronously and reports the count', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)

    // A page stuck at "publishing" with a draft — the exact incident shape. No verified WP connection in
    // the test env, so PostPublisher can't push; the drain still runs and honestly reports 0 published.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Service->value,
        'status' => ContentStatus::Publishing->value, 'title' => 'Mold Testing', 'slug' => Str::slug('Mold Testing'),
        'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(OperateServicePages::class)
        ->call('drainStuckPages')
        ->assertNotified('Published 0 of 1 stuck item(s)');
});
