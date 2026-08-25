<?php

namespace App\Enums;

/**
 * The operator's manual priority on a GEO prompt — the lever that lets a human pin the questions that
 * matter most (a money service, a flagship town) to the FRONT of a budget-bounded check, ahead of the
 * automatic biggest-town-first (size-tier) order. High is measured + bridged into content first, then
 * Normal, then Low; within a tier the town size tier breaks the tie. Default Normal.
 */
enum GeoPromptPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /** Sort weight — lower runs first (High → Normal → Low). */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> value => label, for select filters/actions. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
