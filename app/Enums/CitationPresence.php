<?php

namespace App\Enums;

/**
 * What a scan found for a (location × directory) citation RIGHT NOW (§ Citations). The scanner is the only
 * writer. This is one of the two independent status axes — it answers "is the listing out there, and is it
 * correct" and is completely separate from {@see CitationLifecycleState} ("what work have we done"). Keeping
 * them apart is what lets a listing be lifecycle=verified AND presence=present_mismatch at the same time — a
 * citation we fixed that has since gone wrong — which a single collapsed status could never represent.
 */
enum CitationPresence: string
{
    case Unknown = 'unknown';                   // not scanned yet, or attribution unresolved
    case Absent = 'absent';                     // scanned, no listing for this location
    case PresentMatch = 'present_match';        // listed, NAP matches
    case PresentMismatch = 'present_mismatch';  // listed, but NAP differs — actively wrong

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not scanned',
            self::Absent => 'Missing',
            self::PresentMatch => 'Live',
            self::PresentMismatch => 'Mismatch',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    /** Listed at all (correctly or not). */
    public function isPresent(): bool
    {
        return in_array($this, [self::PresentMatch, self::PresentMismatch], true);
    }

    /**
     * Coverage credit: only a correct listing is coverage. A mismatch counts AGAINST coverage, not toward it —
     * a listing with the wrong phone is not coverage (§ Citations coverage math).
     */
    public function coverageCredit(): float
    {
        return $this === self::PresentMatch ? 1.0 : 0.0;
    }
}
