<?php

use App\Enums\BeatabilityLane;
use App\Enums\ContentStatus;
use App\Filament\Client\Widgets\LeadsHeadlineWidget;
use App\Filament\Client\Widgets\LocalGridWidget;
use App\Filament\Client\Widgets\PerformanceCardsWidget;
use App\Filament\Client\Widgets\PositionTrendWidget;
use App\Filament\Client\Widgets\ProgressWidget;
use App\Models\Content;
use App\Models\Conversion;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

test('the client dashboard widgets render for a client with seeded data', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Conversion::factory()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'target_content_id' => $content->id]);
    $market = Market::factory()->create(['site_id' => $site->id]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 4, 'captured_at' => now()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => $market->id, 'lane' => BeatabilityLane::LocalPack, 'rank' => 2, 'captured_at' => now()]);

    Livewire::test(LeadsHeadlineWidget::class)->assertOk();
    Livewire::test(ProgressWidget::class)->assertOk();
    Livewire::test(PositionTrendWidget::class)->assertOk();
    Livewire::test(LocalGridWidget::class)->assertOk();
    Livewire::test(PerformanceCardsWidget::class)->assertOk();
});
