<?php

use App\Citations\Ui\CitationChip;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationStatus;

function chipStatus(CitationPresence $presence, CitationLifecycleState $lifecycle = CitationLifecycleState::None, ?array $mismatch = null): CitationStatus
{
    return new CitationStatus([
        'presence' => $presence->value,
        'lifecycle' => $lifecycle->value,
        'mismatch_fields' => $mismatch,
    ]);
}

test('an ineligible directory reads Not relevant regardless of status', function (): void {
    expect(CitationChip::for(chipStatus(CitationPresence::PresentMatch), false)['label'])->toBe('Not relevant');
});

test('a null status on an eligible directory reads Not scanned', function (): void {
    expect(CitationChip::for(null, true)['label'])->toBe('Not scanned');
});

test('mismatch outranks a verified listing — the whole point of the two-axis split', function (): void {
    // Verified in the past, but a later scan found it wrong. It must read Mismatch, not Live.
    $chip = CitationChip::for(chipStatus(CitationPresence::PresentMismatch, CitationLifecycleState::Verified), true);

    expect($chip['label'])->toBe('Mismatch')->and($chip['color'])->toBe('warning');
});

test('stalled and rejected outrank presence', function (): void {
    expect(CitationChip::for(chipStatus(CitationPresence::PresentMatch, CitationLifecycleState::Stalled), true)['label'])->toBe('Stalled')
        ->and(CitationChip::for(chipStatus(CitationPresence::Absent, CitationLifecycleState::Rejected), true)['label'])->toBe('Rejected');
});

test('submitted outranks a plain present-match but not a mismatch', function (): void {
    expect(CitationChip::for(chipStatus(CitationPresence::PresentMatch, CitationLifecycleState::Submitted), true)['label'])->toBe('Submitted')
        ->and(CitationChip::for(chipStatus(CitationPresence::PresentMismatch, CitationLifecycleState::Submitted), true)['label'])->toBe('Mismatch');
});

test('plain presence resolves to Live / Missing', function (): void {
    expect(CitationChip::for(chipStatus(CitationPresence::PresentMatch), true)['label'])->toBe('Live')
        ->and(CitationChip::for(chipStatus(CitationPresence::Absent), true)['label'])->toBe('Missing');
});
