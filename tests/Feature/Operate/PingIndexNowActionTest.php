<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

it('submits the site to IndexNow from the board header action', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)

    $stub = Mockery::mock(IndexNowSubmitter::class);
    $stub->shouldReceive('submitSite')->once()->andReturn(['ok' => true, 'submitted' => 12, 'status' => 200, 'reason' => null]);
    app()->instance(IndexNowSubmitter::class, $stub);

    Livewire::test(OperateLocationPages::class)
        ->callAction('pingIndexNow')
        ->assertNotified('Submitted to IndexNow');
});

it('warns when IndexNow rejects the submission', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)

    $stub = Mockery::mock(IndexNowSubmitter::class);
    $stub->shouldReceive('submitSite')->andReturn(['ok' => false, 'submitted' => 0, 'status' => 403, 'reason' => 'update the companion plugin']);
    app()->instance(IndexNowSubmitter::class, $stub);

    Livewire::test(OperateLocationPages::class)
        ->callAction('pingIndexNow')
        ->assertNotified('Could not submit to IndexNow');
});
