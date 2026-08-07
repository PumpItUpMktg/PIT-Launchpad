<?php

use App\Filament\Console\Pages\Corrections;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ccFailedJob(): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Boom',
        'failed_at' => now(),
    ]);
}

it('is reachable only by a Super Admin', function () {
    $this->actingAs(User::factory()->create()); // Operator = Super Admin
    expect(Corrections::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(Corrections::canAccess())->toBeFalse();
});

it('clears failed jobs for a Super Admin', function () {
    $this->actingAs(User::factory()->create());
    ccFailedJob();
    expect(DB::table('failed_jobs')->count())->toBe(1);

    (new Corrections)->clearFailed();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('unlocks a protected page', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'title' => 'Stuck page', 'locked' => true, 'locally_edited' => true,
    ]);

    $console = new Corrections;
    $console->siteId = $site->id;

    expect(collect($console->getLockedProperty())->pluck('id')->all())->toBe([$page->id]);

    $console->unlock($page->id);

    expect($page->fresh()->locked)->toBeFalse()
        ->and($page->fresh()->locally_edited)->toBeFalse();
});

it('does nothing when a Site Admin invokes a correction', function () {
    $this->actingAs(User::factory()->siteAdmin()->create());
    ccFailedJob();

    (new Corrections)->clearFailed(); // capability gate → no-op

    expect(DB::table('failed_jobs')->count())->toBe(1);
});
