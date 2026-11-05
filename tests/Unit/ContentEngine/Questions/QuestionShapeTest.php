<?php

use App\ContentEngine\Questions\QuestionShape;

it('keeps question-word and intent queries, drops keywords and short queries', function () {
    expect(QuestionShape::isQuestion('how long do sump pumps last'))->toBeTrue()
        ->and(QuestionShape::isQuestion('does a sump pump need a dedicated circuit'))->toBeTrue()
        ->and(QuestionShape::isQuestion('sump pump replacement cost'))->toBeTrue()       // intent: cost
        ->and(QuestionShape::isQuestion('pedestal vs submersible sump pump'))->toBeTrue() // intent: vs
        ->and(QuestionShape::isQuestion('sump pump repair'))->toBeFalse()                 // keyword
        ->and(QuestionShape::isQuestion('sump pump design ideas 2025'))->toBeFalse()      // keyword
        ->and(QuestionShape::isQuestion('how much'))->toBeFalse()                         // too short
        ->and(QuestionShape::isQuestion('  '))->toBeFalse();
});

it('excludes the tenant brand by name or domain, never generic words inside the brand', function () {
    $brands = ['Sump Pump Gurus', 'https://www.sumppumpgurus.com'];

    expect(QuestionShape::isQuestion('is sump pump gurus legit', $brands))->toBeFalse()
        ->and(QuestionShape::isQuestion('what is sumppumpgurus.com', $brands))->toBeFalse()
        ->and(QuestionShape::isQuestion('how long do sump pumps last', $brands))->toBeTrue(); // "sump pump" alone is a topic
});

it('mirrors the rule in SQL LIKE patterns', function () {
    $patterns = QuestionShape::likePatterns();

    expect($patterns)->toContain('how %')->toContain('does %')->toContain('%cost%')->toContain('%vs%');
});
