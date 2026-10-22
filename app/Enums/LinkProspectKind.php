<?php

namespace App\Enums;

/**
 * What kind of site a link prospect is (§ Links) — decides the ask: a directory takes a listing, a local
 * site takes a partnership or sponsorship, an editorial site takes a story or a cited resource.
 */
enum LinkProspectKind: string
{
    case Directory = 'directory';
    case Local = 'local';
    case Editorial = 'editorial';
    case National = 'national';
    // Local opportunity lanes (§ Links) — found by the discovery pass, not the competitor intersect.
    case Sponsorship = 'sponsorship';
    case Membership = 'membership';
    case DealerLocator = 'dealer_locator';
    case JobBoard = 'job_board';

    public function label(): string
    {
        return match ($this) {
            self::Directory => 'Directory',
            self::Local => 'Local site',
            self::Editorial => 'Editorial / gov',
            self::National => 'National',
            self::Sponsorship => 'Sponsorship',
            self::Membership => 'Membership',
            self::DealerLocator => 'Dealer locator',
            self::JobBoard => 'Job / training board',
        };
    }

    /** The lanes the local-opportunity discovery pass searches, in the order they are worth a tenant's time. */
    public static function lanes(): array
    {
        return [self::DealerLocator, self::Sponsorship, self::Membership, self::JobBoard];
    }

    public function isLane(): bool
    {
        return in_array($this, self::lanes(), true);
    }
}
