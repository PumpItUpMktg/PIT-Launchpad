<?php

namespace App\Enums;

/**
 * Where a citation status row came from (§ Citations) — recorded on everything so a wrong listing surfacing
 * months later can be traced, and a vendor generating inconsistencies shows up as a pattern.
 */
enum CitationSource: string
{
    case Manual = 'manual';
    case Va = 'va';
    case BulkVendor = 'bulk_vendor';
    case ClientOwned = 'client_owned';
    case Platform = 'platform';
    case Unknown = 'unknown';

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
