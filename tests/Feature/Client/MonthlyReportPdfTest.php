<?php

use App\Client\MonthlyReportPdf;
use App\Enums\BeatabilityLane;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\Account;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

function bindEmptyGsc(): void
{
    app()->instance(SearchConsoleProvider::class, new class implements SearchConsoleProvider
    {
        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000, int $startRow = 0): array
        {
            return [];
        }
    });
}

it('renders a valid PDF branded to the account, sharing the panel view-model', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 15));
    bindEmptyGsc();

    $account = Account::factory()->create(['brand_name' => 'Acme Plumbing', 'primary_color' => '#123456']);
    $site = Site::factory()->create(['account_id' => $account->id, 'brand_name' => 'Acme Plumbing NJ']);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'water heater repair']);
    foreach ([['2026-05-20', 15], ['2026-06-20', 8]] as [$on, $rank]) {
        PositionSnapshot::factory()->create([
            'site_id' => $site->id, 'keyword_id' => $kw->id, 'market_id' => null,
            'lane' => BeatabilityLane::Organic->value, 'rank' => $rank, 'captured_at' => Carbon::parse($on),
        ]);
    }

    $out = app(MonthlyReportPdf::class)->for($site, Carbon::create(2026, 6, 1))->output();

    expect(substr($out, 0, 5))->toBe('%PDF-')
        ->and(strlen($out))->toBeGreaterThan(1000);
});

it('names the file by brand slug and report month', function () {
    $account = Account::factory()->create(['brand_name' => 'Acme Plumbing']);
    $site = Site::factory()->create(['account_id' => $account->id, 'brand_name' => 'Acme Plumbing NJ']);

    expect(app(MonthlyReportPdf::class)->filename($site, Carbon::create(2026, 6, 1)))
        ->toBe('acme-plumbing-nj-2026-06.pdf');
});
