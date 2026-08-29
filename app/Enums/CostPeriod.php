<?php

namespace App\Enums;

/** The billing period for a directory's cost — a one-time fee, or a recurring annual/monthly charge. */
enum CostPeriod: string
{
    case OneTime = 'one_time';
    case Annual = 'annual';
    case Monthly = 'monthly';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }
}
