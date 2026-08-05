<?php

use App\Enums\IndexCoverageState;

it('maps Google coverageState phrases to normalized states', function (string $coverage, ?string $verdict, IndexCoverageState $expected) {
    expect(IndexCoverageState::fromInspection($coverage, $verdict))->toBe($expected);
})->with([
    'submitted and indexed' => ['Submitted and indexed', 'PASS', IndexCoverageState::Indexed],
    'indexed not submitted' => ['Indexed, not submitted in sitemap', 'PASS', IndexCoverageState::Indexed],
    'crawled not indexed' => ['Crawled - currently not indexed', 'NEUTRAL', IndexCoverageState::CrawledNotIndexed],
    'discovered not indexed' => ['Discovered - currently not indexed', 'NEUTRAL', IndexCoverageState::DiscoveredNotIndexed],
    'page with redirect' => ['Page with redirect', 'NEUTRAL', IndexCoverageState::ExcludedRedirect],
    'duplicate canonical' => ['Duplicate without user-selected canonical', 'NEUTRAL', IndexCoverageState::ExcludedCanonical],
    'alternate page' => ['Alternate page with proper canonical tag', 'NEUTRAL', IndexCoverageState::ExcludedCanonical],
    'noindex' => ["Excluded by 'noindex' tag", 'FAIL', IndexCoverageState::ExcludedBlocked],
    'unknown to google' => ['URL is unknown to Google', 'NEUTRAL', IndexCoverageState::Unknown],
]);

it('falls back to the verdict when coverageState is empty', function () {
    expect(IndexCoverageState::fromInspection('', 'PASS'))->toBe(IndexCoverageState::Indexed)
        ->and(IndexCoverageState::fromInspection('', 'FAIL'))->toBe(IndexCoverageState::Unknown)
        ->and(IndexCoverageState::fromInspection(null, null))->toBe(IndexCoverageState::Unknown);
});

it('treats only the Indexed state as truly indexed', function () {
    expect(IndexCoverageState::Indexed->indexed())->toBeTrue()
        ->and(IndexCoverageState::ExcludedRedirect->indexed())->toBeFalse()
        ->and(IndexCoverageState::CrawledNotIndexed->indexed())->toBeFalse()
        ->and(IndexCoverageState::NotInspected->indexed())->toBeFalse();
});
