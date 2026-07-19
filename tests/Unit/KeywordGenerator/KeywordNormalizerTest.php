<?php

use App\Integrations\DataForSeo\DataForSeoClient;
use App\KeywordGenerator\Corpus\KeywordNormalizer;

it('collapses case, punctuation, whitespace and plurals to one canonical', function () {
    $n = new KeywordNormalizer;

    expect($n->canonical('French Drains'))->toBe('french drain')
        ->and($n->canonical('  french   drain '))->toBe('french drain')
        ->and($n->canonical('French-Drain!'))->toBe('french drain')
        ->and($n->canonical('sump pumps'))->toBe('sump pump')
        ->and($n->canonical('utilities'))->toBe('utility')      // ies -> y
        ->and($n->canonical('boxes'))->toBe('box');             // es -> ''
});

it('keeps short words and -ss words intact', function () {
    $n = new KeywordNormalizer;

    expect($n->canonical('gas'))->toBe('gas')          // <= 3 chars, untouched
        ->and($n->canonical('access'))->toBe('access') // -ss, untouched
        ->and($n->canonical(''))->toBe('');
});

it('the two SPG drainage heads normalize distinctly (no false merge)', function () {
    $n = new KeywordNormalizer;

    expect($n->canonical('French Drain Installation'))->toBe('french drain installation')
        ->and($n->canonical('Curtain Drain'))->toBe('curtain drain')
        ->and($n->canonical('French Drain Installation'))->not->toBe($n->canonical('Curtain Drain'));
});

it('parses related-keyword ideas keeping volume/competition/difficulty', function () {
    $result = [
        ['keyword_data' => [
            'keyword' => 'french drain cost',
            'keyword_info' => ['search_volume' => 2400, 'competition' => 0.42],
            'keyword_properties' => ['keyword_difficulty' => 31],
        ]],
        ['keyword_data' => ['keyword' => 'french drain cost']], // dup — dropped
        ['keyword' => 'curtain drain', 'keyword_info' => ['search_volume' => 5180]], // flat shape tolerated
    ];

    $ideas = DataForSeoClient::parseRelatedIdeas($result);

    expect($ideas)->toHaveCount(2)
        ->and($ideas[0]->keyword)->toBe('french drain cost')
        ->and($ideas[0]->volume)->toBe(2400)
        ->and($ideas[0]->competition)->toBe(0.42)
        ->and($ideas[0]->difficulty)->toBe(31)
        ->and($ideas[1]->keyword)->toBe('curtain drain')
        ->and($ideas[1]->volume)->toBe(5180)
        ->and($ideas[1]->difficulty)->toBeNull();
});
