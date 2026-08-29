<?php

namespace App\Enums;

/**
 * Why a tenant-wide phone number is shared across locations — a corporate main line, an emergency line, or a
 * call-tracking number. Shared numbers appear in listings everywhere by design, so (unless a location owns one
 * as its GBP primary) they carry ZERO attribution signal in the citation scan — address decides.
 */
enum SharedPhonePurpose: string
{
    case Corporate = 'corporate';
    case Emergency = 'emergency';
    case Tracking = 'tracking';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }
}
