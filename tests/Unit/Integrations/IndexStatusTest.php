<?php

use App\Enums\IndexCoverageState;
use App\Integrations\UrlInspection\IndexStatus;
use Illuminate\Support\Carbon;

it('round-trips through toArray/fromArray', function () {
    $s = new IndexStatus(
        url: 'https://spg.example/hoboken-nj',
        state: IndexCoverageState::Indexed,
        coverageState: 'Submitted and indexed',
        verdict: 'PASS',
        googleCanonical: 'https://spg.example/hoboken-nj',
        userCanonical: 'https://spg.example/hoboken-nj',
        lastCrawledAt: Carbon::parse('2026-08-01T12:00:00Z'),
    );

    $back = IndexStatus::fromArray($s->toArray());

    expect($back->state)->toBe(IndexCoverageState::Indexed)
        ->and($back->coverageState)->toBe('Submitted and indexed')
        ->and($back->indexed())->toBeTrue()
        ->and($back->canonicalMismatch())->toBeFalse();
});

it('detects a canonical mismatch ignoring a trailing slash', function () {
    $same = new IndexStatus('https://spg.example/x', IndexCoverageState::Indexed, 'Indexed', googleCanonical: 'https://spg.example/x/');
    $diff = new IndexStatus('https://spg.example/x', IndexCoverageState::ExcludedCanonical, 'Duplicate', googleCanonical: 'https://spg.example/y');

    expect($same->canonicalMismatch())->toBeFalse()
        ->and($diff->canonicalMismatch())->toBeTrue();
});
