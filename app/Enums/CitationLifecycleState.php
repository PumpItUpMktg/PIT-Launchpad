<?php

namespace App\Enums;

/**
 * What work the platform has done on a (location × directory) citation (§ Citations). {@see CitationLifecycle}
 * is the only writer. This is the second of the two independent status axes — completely separate from
 * {@see CitationPresence} ("what's out there right now"). A routine scan never touches it, so submitting or
 * verifying a listing is never clobbered by the next scan pass.
 */
enum CitationLifecycleState: string
{
    case None = 'none';           // no work started
    case Submitted = 'submitted'; // a VA / operator submitted it; awaiting a scan to confirm
    case Verified = 'verified';   // a later scan confirmed the submission landed
    case Rejected = 'rejected';   // the directory / VA rejected the submission
    case Stalled = 'stalled';     // submitted/work-ordered too many times without resolving

    public function label(): string
    {
        return match ($this) {
            self::None => 'No action',
            self::Submitted => 'Submitted',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Stalled => 'Stalled',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    /** A submission is out and awaiting confirmation. */
    public function isInFlight(): bool
    {
        return $this === self::Submitted;
    }
}
