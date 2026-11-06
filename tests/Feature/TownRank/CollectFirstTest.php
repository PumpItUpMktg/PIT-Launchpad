<?php

use App\Filament\Pages\TownRankPage;
use App\Jobs\CollectTownRankScan;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use App\TownRank\TownRankBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin()->create());
});

function collectFirstSite(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scans = [];
    foreach (['town_query', 'local'] as $mode) {
        $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => $mode, 'status' => 'pending', 'points_count' => 1, 'scanned_at' => now()]);
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'Town', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 't-'.$mode]);
        $scans[] = $scan;
    }

    return [$site, $kw, $scans];
}

it('the card offers Collect first while collecting; the toggle stamps both modes, queues their collectors now, and clears on a second click', function () {
    Bus::fake();
    [$site, $kw, $scans] = collectFirstSite();

    $page = Livewire::test(TownRankPage::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('⇧ Collect first');

    $page->call('collectFirst', (string) $kw->id);

    foreach ($scans as $scan) {
        expect($scan->fresh()->collect_first_at)->not->toBeNull();
    }
    Bus::assertDispatchedTimes(CollectTownRankScan::class, 2);
    expect(collect(app(TownRankBoard::class)->cards($site))->firstWhere('keyword_id', (string) $kw->id)['collect_first'])->toBeTrue();
    $page->assertSee('⇧ Collecting first');

    $page->call('collectFirst', (string) $kw->id);
    foreach ($scans as $scan) {
        expect($scan->fresh()->collect_first_at)->toBeNull();
    }
    expect(CollectTownRankScan::collectFirstPending())->toBeFalse();
});

it('a completed keyword has no button and the stamp means nothing once its scans are done', function () {
    [$site, $kw, $scans] = collectFirstSite();
    foreach ($scans as $scan) {
        $scan->forceFill(['status' => 'complete', 'collect_first_at' => now()])->save();
    }

    Livewire::test(TownRankPage::class)->set('siteId', $site->id)->assertOk()->assertDontSee('Collect first');
    expect(CollectTownRankScan::collectFirstPending())->toBeFalse()
        ->and(collect(app(TownRankBoard::class)->cards($site))->firstWhere('keyword_id', (string) $kw->id)['collect_first'])->toBeFalse();
});
