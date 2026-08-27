<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a coverage scan plan re-runs. Deliberately coarse — geo-grid scans cost DataForSEO credits, so
 * Monthly is the sensible default and the finest cadence offered is Weekly. `Off` keeps a plan configured but
 * dormant (no next run).
 */
enum ScanCadence: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Off => 'Off (paused)',
        };
    }

    /** The next run time after $from, or null when the cadence is Off (plan is dormant). */
    public function advance(Carbon $from): ?Carbon
    {
        return match ($this) {
            self::Monthly => $from->copy()->addMonth(),
            self::Weekly => $from->copy()->addWeek(),
            self::Off => null,
        };
    }

    /** Filament select options. @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }
}
