<?php

namespace App\Enums;

/**
 * What it takes to acquire a listing — free vs paid vs a membership/account/license. Groups the work order's
 * "new submissions" (free → straight to the VA; anything with a cost → grouped for one-pass operator spend
 * approval) and routes membership/account/license items to the client-action section.
 */
enum AcquisitionType: string
{
    case Free = 'free';
    case PaidOneTime = 'paid_one_time';
    case PaidRecurring = 'paid_recurring';
    case Membership = 'membership';
    case AccountRequired = 'account_required';
    case LicenseRequired = 'license_required';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    /** Free items need no spend approval and go straight to the VA. */
    public function isFree(): bool
    {
        return $this === self::Free;
    }

    /** Membership / account / license items are client-action, not VA work. */
    public function isClientAction(): bool
    {
        return in_array($this, [self::Membership, self::AccountRequired, self::LicenseRequired], true);
    }
}
