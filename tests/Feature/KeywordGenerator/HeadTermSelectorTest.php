<?php

use App\KeywordGenerator\Cluster\HeadTermSelector;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\KeywordCorpus;

function member(string $term, int $volume, string $intent): KeywordCorpus
{
    return KeywordCorpus::make(['term' => $term, 'canonical' => mb_strtolower($term), 'volume' => $volume, 'intent' => $intent]);
}

it('heads on the highest-volume term outright when volumes differ widely', function () {
    $head = (new HeadTermSelector(new IntentClassifier))->select([
        member('french drain guide', 5000, 'informational'),
        member('french drain cost', 1200, 'commercial'),
    ]);

    expect($head->term)->toBe('french drain guide'); // 5000 vs 1200 — not similar, volume wins
});

it('prefers a commercial/transactional term over an informational one at similar volume', function () {
    $head = (new HeadTermSelector(new IntentClassifier))->select([
        member('how to fix a sump pump', 5000, 'informational'),
        member('sump pump repair', 4600, 'transactional'), // within 80% band → intent wins
    ]);

    expect($head->term)->toBe('sump pump repair');
});

it('exposes the top-2 candidates for SERP validation', function () {
    $candidates = (new HeadTermSelector(new IntentClassifier))->candidates([
        member('a', 100, 'commercial'),
        member('b', 900, 'commercial'),
        member('c', 500, 'commercial'),
    ], 2);

    expect(array_map(fn ($m) => $m->term, $candidates))->toBe(['b', 'c']); // top 2 by volume
});
