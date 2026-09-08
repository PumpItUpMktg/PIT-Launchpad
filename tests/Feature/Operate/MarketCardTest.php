<?php

use App\Enums\RankingState;
use App\Operate\MarketCard;
use App\Support\FreshnessStamp;

/** A fully-populated card projects every key, and the absent-state nulls survive to the array verbatim. */
it('projects a populated card to the flat component view', function () {
    $card = new MarketCard(
        id: 'loc1', name: 'Hoboken, NJ', county: null, state: 'NJ',
        marketPageUrl: 'https://x.example/hoboken/', marketPagePosition: 3, marketPageIndexState: 'indexed',
        gbpUrl: 'https://www.google.com/maps/place/?q=place_id:ChIJ', townsWithPages: 14, townsTotal: 22,
        sizeTiers: [['tier' => 'major', 'label' => 'Major', 'built' => 1, 'served' => 2]],
        held: false, draftedPages: 0, countyMismatch: null,
        impressions: 150, impressionsDelta: 20, clicks: 15, clicksDelta: -2, sessions: 40,
        rankingState: RankingState::Ranked, rankTop3: 1, rankPageOne: 1, rankBeyond: 0,
        reviewsCount: 2, reviewsAvg: 4.5, citationsLive: 3, jobsCount: null,
        gscFreshness: FreshnessStamp::for(now()->subDay(), 86_400, noun: 'search data'),
    );

    $a = $card->toArray();

    expect($a['name'])->toBe('Hoboken, NJ')
        ->and($a['to_deploy'])->toBe(8)              // 22 − 14
        ->and($a['ranking_state'])->toBe('ranked')
        ->and($a['rank_top3'])->toBe(1)
        ->and($a['sessions'])->toBe(40)
        ->and($a['jobs_count'])->toBeNull()          // not_tracked survives as null (never 0)
        ->and($a['gsc_freshness'])->toHaveKeys(['line', 'severity'])
        ->and($a['ga4_freshness'])->toBeNull();      // absent stamp is null, not a blank
});

/** A held/empty market carries no metrics: the nulls are the honest not-tracked state, distinct from 0. */
it('keeps absent metrics null and clamps to_deploy at zero', function () {
    $card = new MarketCard(
        id: 'loc2', name: 'Trenton', county: null, state: null,
        marketPageUrl: null, marketPagePosition: null, marketPageIndexState: 'unchecked', gbpUrl: null,
        townsWithPages: 3, townsTotal: 0, sizeTiers: [], held: true, draftedPages: 2, countyMismatch: null,
    );

    $a = $card->toArray();

    expect($a['impressions'])->toBeNull()
        ->and($a['clicks'])->toBeNull()
        ->and($a['sessions'])->toBeNull()
        ->and($a['ranking_state'])->toBe('not_tracked')
        ->and($a['to_deploy'])->toBe(0)              // clamped (built can exceed served for a held stub)
        ->and($a['held'])->toBeTrue()
        ->and($a['drafted_pages'])->toBe(2);
});
