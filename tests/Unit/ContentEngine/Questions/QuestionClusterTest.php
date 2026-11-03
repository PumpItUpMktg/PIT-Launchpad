<?php

use App\ContentEngine\Questions\QuestionCluster;
use App\ContentEngine\Questions\QuestionStat;

function cstat(string $q, int $imp, int $clk = 1, ?float $pos = 10.0, array $urls = []): QuestionStat
{
    return new QuestionStat($q, $imp, $clk, $pos, $pos, 10, '2026-08-01', '2026-10-30', 5, $urls);
}

it('normalizes phrasings to a token set without filler or plurals', function () {
    expect(QuestionCluster::tokens('how long do sump pumps last'))->toBe(['how', 'long', 'sump', 'pump', 'last'])
        ->and(QuestionCluster::tokens('How long does a sump pump last?'))->toBe(['how', 'long', 'sump', 'pump', 'last']);
});

it('clusters near-identical phrasings under the lead with the most impressions and sums their demand', function () {
    $clusters = QuestionCluster::cluster([
        cstat('how long does a sump pump last', 40, 1, 20.0, ['https://x.test/a/' => ['impressions' => 40, 'clicks' => 1, 'position' => 20.0]]),
        cstat('how long do sump pumps last', 100, 2, 10.0, ['https://x.test/a/' => ['impressions' => 100, 'clicks' => 2, 'position' => 10.0]]),
        cstat('does a sump pump need a dedicated circuit', 30, 1, 8.0, ['https://x.test/b/' => ['impressions' => 30, 'clicks' => 1, 'position' => 8.0]]),
    ], 0.75);

    expect($clusters)->toHaveCount(2)
        ->and($clusters[0]->query())->toBe('how long do sump pumps last')
        ->and($clusters[0]->otherPhrasings())->toBe(['how long does a sump pump last'])
        ->and($clusters[0]->impressions())->toBe(140)
        ->and($clusters[0]->clicks())->toBe(3)
        ->and($clusters[0]->position())->toBe(12.9)   // (10×100 + 20×40) / 140
        ->and($clusters[0]->topUrl())->toBe('https://x.test/a/')
        ->and($clusters[0]->topUrlPosition())->toBe(12.9)
        ->and($clusters[1]->query())->toBe('does a sump pump need a dedicated circuit');
});

it('keeps distinct questions apart even when they share a head term', function () {
    $clusters = QuestionCluster::cluster([
        cstat('how much does sump pump installation cost', 50),
        cstat('how often should a sump pump run', 50),
        cstat('why does my sump pump keep running', 50),
    ]);

    expect($clusters)->toHaveCount(3);
});
