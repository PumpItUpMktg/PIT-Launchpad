<?php

use App\Client\PositionTrends;
use App\Enums\BeatabilityLane;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

test('the conversion model has no ROI / attribution column', function () {
    $columns = Schema::getColumnListing('conversions');

    foreach ($columns as $column) {
        expect($column)->not->toContain('roi')
            ->and($column)->not->toContain('attribut')
            ->and($column)->not->toContain('value');
    }
});

test('refresh markers are date-only correlation annotations, with no causal/ROI field', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'target_content_id' => $content->id]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 5, 'captured_at' => now()]);
    RefreshEvent::factory()->create(['site_id' => $site->id, 'content_id' => $content->id]);

    $trend = app(PositionTrends::class)->forKeyword($keyword);

    // The output exposes correlation data only — no attribution/ROI keys.
    expect(array_keys($trend))->toBe(['series', 'refresh_markers', 'standings'])
        ->and(array_keys($trend['refresh_markers'][0]))->toBe(['date']);
});

test('the position-trend view frames refresh as observed correlation, never causal ROI', function () {
    $blade = file_get_contents(resource_path('views/filament/client/widgets/position-trend.blade.php'));

    expect($blade)->toContain('correlation')
        ->and($blade)->not->toContain('ROI')
        ->and(strtolower($blade))->not->toContain('drove')
        ->and(strtolower($blade))->not->toContain('caused');
});
