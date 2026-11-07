<?php

namespace App\Enums;

/**
 * The networks a share can go to. GBP is per physical location (a post on that location's Business
 * Profile); Facebook and Instagram are normally one Page / account per business.
 */
enum SocialNetwork: string
{
    case Gbp = 'gbp';
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Gbp => 'Google Business Profile',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
        };
    }

    /** GBP posts cap at 1,500 characters; the others are generous enough that the composer's own cap rules. */
    public function maxChars(): int
    {
        return match ($this) {
            self::Gbp => 1500,
            self::Facebook => 2000,
            self::Instagram => 2200,
        };
    }

    /** Hashtags render as plain text on GBP and do nothing there — the composer leaves them off. */
    public function usesHashtags(): bool
    {
        return $this !== self::Gbp;
    }

    /** Instagram captions carry no clickable link — the link is recorded but not written into the copy. */
    public function linkInCopy(): bool
    {
        return $this !== self::Instagram;
    }

    /** GBP channels belong to a physical location; the others belong to the site. */
    public function perLocation(): bool
    {
        return $this === self::Gbp;
    }
}
