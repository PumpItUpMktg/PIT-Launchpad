<?php

use App\Enums\BeatabilityLane;
use App\Filament\Client\Pages\MonthlyReport;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

afterEach(fn () => Carbon::setTestNow());

test('the monthly report renders for a client, empty-gracefully with no data', function () {
    ['user' => $client] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Livewire::test(MonthlyReport::class)
        ->assertOk()
        ->assertSee('Search ranking movement')
        ->assertSee('Search visibility');
});

test('the monthly report shows a climbing keyword when the data supports it', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 15));
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency plumber']);
    foreach ([['2026-06-20', 15], ['2026-07-10', 8]] as [$on, $rank]) {
        PositionSnapshot::factory()->create([
            'site_id' => $site->id, 'keyword_id' => $kw->id, 'market_id' => null,
            'lane' => BeatabilityLane::Organic->value, 'rank' => $rank, 'captured_at' => Carbon::parse($on),
        ]);
    }

    Livewire::test(MonthlyReport::class)
        ->set('monthKey', '2026-07')
        ->assertOk()
        ->assertSee('emergency plumber')
        ->assertSee('Top climbers');
});

test('the report view frames movement as observed, never guaranteed or attributed', function () {
    $raw = file_get_contents(resource_path('views/filament/client/pages/monthly-report.blade.php'));
    $lower = strtolower($raw);

    expect($lower)->toContain('observed')
        ->not->toContain('guaranteed')
        ->not->toContain('attributed')
        ->not->toContain('caused')
        ->not->toContain('promised');
    expect($raw)->not->toContain('ROI');
});
