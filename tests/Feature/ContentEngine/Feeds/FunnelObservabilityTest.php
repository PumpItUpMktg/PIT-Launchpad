<?php

use App\ContentEngine\Feeds\FeedIngestReport;
use App\ContentEngine\FunnelResult;
use App\Integrations\News\RssFeed;
use App\Models\Content;
use Tests\Support\Feeds;

/**
 * Regression for the deployed "0 candidates" funnel: the RSS <description> snippet
 * must be captured, or every item reaches the candidate funnel with an empty
 * summary and is dropped by the pre-filter before scoring — silently.
 */
it('captures the RSS <description> as the item summary (HTML-stripped)', function () {
    $rows = RssFeed::parse(Feeds::directXml());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['summary'])->toBe('A short snippet describing the development.'); // tags + entities resolved
});

it('parses items with no <description> to an empty summary, not a missing key', function () {
    $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>X</title>'
        .'<item><title>Headline only</title><link>https://x.example/a</link></item></channel></rss>';

    $rows = RssFeed::parse($xml);

    expect($rows[0])->toHaveKey('summary')
        ->and($rows[0]['summary'])->toBe('');
});

it('breaks a funnel result into legible per-stage counts', function () {
    // 10 fetched → 4 dropped at pre-filter, 2 score-rejected, 2 routed, 1 parked,
    // leaving 1 merged away by same-story clustering.
    $dropped = array_merge(
        array_fill(0, 4, ['title' => 'junk', 'reason' => 'pre_filter']),
        [['title' => 'a', 'reason' => 'below_threshold'], ['title' => 'b', 'reason' => 'no_silo_match']],
    );
    $funnel = new FunnelResult([new Content, new Content], [new Content], [], [], $dropped);

    $report = FeedIngestReport::fromFunnel('f1', 'Flooding · Austin TX (Google News)', 10, $funnel);

    expect($report->fetched)->toBe(10)
        ->and($report->prefilteredOut)->toBe(4)
        ->and($report->scoreRejected)->toBe(2)
        ->and($report->routed)->toBe(2)
        ->and($report->parked)->toBe(1)
        ->and($report->deduped)->toBe(1)     // (10 - 4) - (2 + 1 + 0 + 2)
        ->and($report->line())->toContain('fetched 10');
});
