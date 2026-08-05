<?php

use App\Publishing\Seo\KeywordUsageAuditor;

it('matches an exact normalized phrase regardless of case and punctuation', function () {
    expect(KeywordUsageAuditor::placement('sump pump installation', 'Professional Sump-Pump Installation!'))
        ->toBe(KeywordUsageAuditor::EXACT);
});

it('reports a partial match when all tokens are present but not as a phrase', function () {
    expect(KeywordUsageAuditor::placement('sump pump installation', 'Installation of a reliable sump pump'))
        ->toBe(KeywordUsageAuditor::PARTIAL);
});

it('reports absent when any token is missing', function () {
    expect(KeywordUsageAuditor::placement('battery backup sump pump', 'sump pump repair services'))
        ->toBe(KeywordUsageAuditor::ABSENT);
});

it('grades optimized only when title + h1 are exact and slug is present', function () {
    $exactEverywhere = ['slug' => 'exact', 'title' => 'exact', 'h1' => 'exact', 'meta_description' => 'partial', 'body' => 'exact'];
    expect(KeywordUsageAuditor::verdict($exactEverywhere))->toBe('optimized');
});

it('grades off_target when the keyword is missing from the title or the H1', function () {
    $missingH1 = ['slug' => 'exact', 'title' => 'exact', 'h1' => 'absent', 'meta_description' => 'exact', 'body' => 'exact'];
    expect(KeywordUsageAuditor::verdict($missingH1))->toBe('off_target');
});

it('grades partial when present but not tight in the critical spots', function () {
    $loose = ['slug' => 'exact', 'title' => 'partial', 'h1' => 'exact', 'meta_description' => 'absent', 'body' => 'exact'];
    expect(KeywordUsageAuditor::verdict($loose))->toBe('partial');
});
