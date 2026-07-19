<?php

use App\Integrations\Embedding\EmbeddingProvider;
use App\KeywordGenerator\Cluster\ClusterEngine;
use App\KeywordGenerator\Cluster\CorpusEmbeddings;
use App\Models\KeywordCorpus;

/** Substring-mapped embeddings: same family → identical vector (cosine 1), different → orthogonal (0). */
class FamilyEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'french drain') => [1.0, 0.0, 0.0],
            str_contains($t, 'sump pump') => [0.0, 1.0, 0.0],
            str_contains($t, 'crawl space') => [0.0, 0.0, 1.0],
            default => [0.3, 0.3, 0.3],
        };
    }
}

function corpusTerm(string $term, int $volume): KeywordCorpus
{
    return KeywordCorpus::make(['term' => $term, 'canonical' => mb_strtolower($term), 'volume' => $volume]);
}

it('groups terms into demand clusters by embedding similarity, deterministically', function () {
    $engine = new ClusterEngine(new CorpusEmbeddings(new FamilyEmbeddings));

    $terms = [
        corpusTerm('French Drain Installation', 5180),
        corpusTerm('French Drain Cost', 2400),
        corpusTerm('Sump Pump Repair', 2720),
        corpusTerm('Sump Pump Installation', 1900),
        corpusTerm('Crawl Space Encapsulation', 3360),
    ];

    $clusters = $engine->cluster($terms, 0.70);

    // Three families → three clusters.
    expect($clusters)->toHaveCount(3);

    $byHead = [];
    foreach ($clusters as $members) {
        $canons = array_map(fn (KeywordCorpus $m) => $m->canonical, $members);
        sort($canons);
        $byHead[$members[0]->canonical] = $canons; // members[0] is the highest-volume (deterministic order)
    }

    // French drain family clustered together, headed by the higher-volume term.
    expect($byHead)->toHaveKey('french drain installation')
        ->and($byHead['french drain installation'])->toBe(['french drain cost', 'french drain installation'])
        ->and($byHead)->toHaveKey('sump pump repair')
        ->and($byHead['sump pump repair'])->toBe(['sump pump installation', 'sump pump repair'])
        // Crawl space encapsulation stands alone as its own head — never a section under a low-volume hub.
        ->and($byHead)->toHaveKey('crawl space encapsulation');
});

it('is re-runnable — same corpus yields identical clusters', function () {
    $engine = new ClusterEngine(new CorpusEmbeddings(new FamilyEmbeddings));
    $terms = [corpusTerm('French Drain Cost', 2400), corpusTerm('French Drain Installation', 5180), corpusTerm('Sump Pump Repair', 2720)];

    $a = $engine->cluster($terms, 0.70);
    $b = (new ClusterEngine(new CorpusEmbeddings(new FamilyEmbeddings)))->cluster($terms, 0.70);

    $heads = fn (array $cs) => array_map(fn ($m) => $m[0]->canonical, $cs);
    expect($heads($a))->toBe($heads($b));
});
