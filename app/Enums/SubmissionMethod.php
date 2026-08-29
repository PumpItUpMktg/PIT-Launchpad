<?php

namespace App\Enums;

/**
 * How a listing is submitted to a directory — the VA-facing claim flow. Drives whether a task is VA work or
 * needs client action (an account/license the business must hold).
 */
enum SubmissionMethod: string
{
    case Form = 'form';
    case Paid = 'paid';
    case RequiresAccount = 'requires_account';
    case RequiresLicense = 'requires_license';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }
}
