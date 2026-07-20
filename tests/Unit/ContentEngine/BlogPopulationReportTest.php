<?php

use App\ContentEngine\Feeds\BlogPopulationReport;

function popReport(array $o = []): BlogPopulationReport
{
    return new BlogPopulationReport(
        keywordsTotal: $o['keywordsTotal'] ?? 10,
        keywordsSiloed: $o['keywordsSiloed'] ?? 10,
        rebucketed: $o['rebucketed'] ?? 0,
        feedsActive: $o['feedsActive'] ?? 5,
        feedsUpserted: $o['feedsUpserted'] ?? 5,
        ingested: $o['ingested'] ?? true,
        fetched: $o['fetched'] ?? 8,
        candidatesCreated: $o['candidatesCreated'] ?? 3,
        parked: $o['parked'] ?? 0,
    );
}

test('the diagnosis names each broken link in order', function () {
    expect(popReport(['keywordsTotal' => 0])->diagnosis())->toContain('Discover keywords');
    expect(popReport(['keywordsTotal' => 10, 'keywordsSiloed' => 0])->diagnosis())->toContain('routed to a silo');
    expect(popReport(['keywordsSiloed' => 10, 'feedsActive' => 0])->diagnosis())->toContain('no news feeds');
});

test('the not-yet-ingested state reads as in-progress, not empty', function () {
    $r = popReport(['ingested' => false, 'feedsActive' => 4, 'keywordsSiloed' => 6, 'fetched' => 0, 'candidatesCreated' => 0]);

    expect($r->ready())->toBeTrue()
        ->and($r->diagnosis())->toContain('Fetching news now')
        ->and($r->diagnosis())->toContain('4 news feed');
});

test('a fetch that returned nothing and a scored-out fetch read differently from a win', function () {
    expect(popReport(['fetched' => 0, 'candidatesCreated' => 0])->diagnosis())->toContain('no items this run');
    expect(popReport(['fetched' => 8, 'candidatesCreated' => 0, 'parked' => 2])->diagnosis())->toContain('none passed relevance');
    expect(popReport(['fetched' => 8, 'candidatesCreated' => 3])->diagnosis())->toContain('Created 3 candidate');
});
