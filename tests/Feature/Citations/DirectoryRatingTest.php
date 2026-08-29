<?php

use App\Citations\DirectoryRating;
use App\Enums\DirectoryRecommendation;
use App\Models\Directory;
use App\Models\DirectoryMarketSignal;

beforeEach(function (): void {
    $this->rating = new DirectoryRating;
});

test('seo value comes from domain rank, falls back to authority tier, and discounts nofollow', function (): void {
    $ranked = Directory::factory()->create(['domain_rank' => 80, 'is_nofollow' => false]);
    $tierOnly = Directory::factory()->create(['domain_rank' => null, 'authority_tier' => 4]); // 4 × 20 = 80
    $nofollow = Directory::factory()->create(['domain_rank' => 80, 'is_nofollow' => true]);   // 80 × 0.6 = 48

    expect($this->rating->seoValue($ranked))->toBe(80)
        ->and($this->rating->seoValue($tierOnly))->toBe(80)
        ->and($this->rating->seoValue($nofollow))->toBe(48);
});

test('a per-market seo value overrides the global value', function (): void {
    $dir = Directory::factory()->create(['domain_rank' => 80]);
    DirectoryMarketSignal::factory()->for($dir)->create(['geo_value' => 'Clifton', 'seo_value_local' => 95]);
    $dir->load('marketSignals');

    expect($this->rating->seoValue($dir, 'Clifton'))->toBe(95)
        ->and($this->rating->seoValue($dir))->toBe(80);
});

test('recommendation labels reflect value and cost', function (): void {
    $mustHave = Directory::factory()->create(['domain_rank' => 80, 'cost_amount' => null]);
    $recommended = Directory::factory()->create(['domain_rank' => 40, 'cost_amount' => null]);
    $lowValue = Directory::factory()->create(['domain_rank' => 15, 'cost_amount' => null]);
    $worthPaying = Directory::factory()->create(['domain_rank' => 80, 'cost_amount' => 10]); // $10/80 = 0.125/pt
    $skipPaid = Directory::factory()->create(['domain_rank' => 80, 'cost_amount' => 200]);    // $200/80 = 2.5/pt

    expect($this->rating->recommendation($mustHave))->toBe(DirectoryRecommendation::MustHave)
        ->and($this->rating->recommendation($recommended))->toBe(DirectoryRecommendation::Recommended)
        ->and($this->rating->recommendation($lowValue))->toBe(DirectoryRecommendation::LowValue)
        ->and($this->rating->recommendation($worthPaying))->toBe(DirectoryRecommendation::WorthPaying)
        ->and($this->rating->recommendation($skipPaid))->toBe(DirectoryRecommendation::SkipPaid);
});

test('rate persists the computed seo value', function (): void {
    $dir = Directory::factory()->create(['domain_rank' => 72, 'seo_value' => null]);

    $value = $this->rating->rate($dir);

    expect($value)->toBe(72)->and($dir->refresh()->seo_value)->toBe(72);
});

test('rateMarket rewards a directory that ranks locally near the top', function (): void {
    $dir = Directory::factory()->create(['domain_rank' => 50]);
    $signal = DirectoryMarketSignal::factory()->for($dir)->create([
        'geo_value' => 'Clifton',
        'ranks_for_local_terms' => true,
        'local_serp_positions' => [['term' => 'plumber clifton', 'position' => 2]],
        'competitor_count' => 5,
    ]);

    $value = $this->rating->rateMarket($signal);

    expect($value)->toBe(80) // 50 base + 30 top-3 boost
        ->and($signal->refresh()->seo_value_local)->toBe(80)
        ->and($signal->last_evaluated_at)->not->toBeNull();
});
