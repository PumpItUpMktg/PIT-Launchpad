<?php

namespace App\Enums;

/**
 * Whether a directory accepts one entry per business or one per address — decides if a sibling location's
 * listing already satisfies THIS location's coverage (one_per_business → covered_by_sibling, no submission
 * task) or not (one_per_address / unlimited → each location needs its own entry). New catalog entries default
 * to one_per_address, which fails safe (worst case a submission is rejected and the truth is learned).
 */
enum MultiLocationPolicy: string
{
    case OnePerBusiness = 'one_per_business';
    case OnePerAddress = 'one_per_address';
    case Unlimited = 'unlimited';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    /** A sibling's listing satisfies this location's coverage only for a one-entry-per-business directory. */
    public function siblingListingCovers(): bool
    {
        return $this === self::OnePerBusiness;
    }
}
