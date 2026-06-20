<?php

namespace App\Enums;

use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Guided\Territory;

/**
 * The guided setup flow: four setup steps + the Grow dashboard. The order is the pipeline's
 * dependency order made structural — each step's {@see prerequisiteFlag()} (the prior step's
 * completion) must be set before it unlocks, so territory can't be skipped ahead of structure
 * and volume always grounds against a real territory. {@see completionFlag()} is the gate a
 * step sets when its work is done.
 */
enum SetupStep: int
{
    case Business = 1;
    case Territory = 2;
    case Structure = 3;
    case Approve = 4;
    case Grow = 5;

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business & services',
            self::Territory => 'Territory',
            self::Structure => 'Structure',
            self::Approve => 'Approve & build',
            self::Grow => 'Grow',
        };
    }

    public function sublabel(): string
    {
        return match ($this) {
            self::Business => 'What you do',
            self::Territory => 'Where you work',
            self::Structure => 'Your pages',
            self::Approve => 'Go live',
            self::Grow => 'Your live site',
        };
    }

    /** The "Step N of 4" eyebrow; Grow stands apart. */
    public function eyebrow(): string
    {
        return $this === self::Grow ? 'Grow' : "Step {$this->value} of 4";
    }

    public function isGrow(): bool
    {
        return $this === self::Grow;
    }

    /** The SetupState boolean that must be true for this step to unlock (null = always open). */
    public function prerequisiteFlag(): ?string
    {
        return match ($this) {
            self::Business => null,
            self::Territory => 'services_done',
            self::Structure => 'territory_done',
            self::Approve => 'structure_finalized',
            self::Grow => 'approved',
        };
    }

    /** The SetupState boolean this step sets when its work completes (null = nothing to set). */
    public function completionFlag(): ?string
    {
        return match ($this) {
            self::Business => 'services_done',
            self::Territory => 'territory_done',
            self::Structure => 'structure_finalized',
            self::Approve => 'approved',
            self::Grow => null,
        };
    }

    /** The Filament page backing this step. */
    public function pageClass(): string
    {
        return match ($this) {
            self::Business => Business::class,
            self::Territory => Territory::class,
            self::Structure => Structure::class,
            self::Approve => Approve::class,
            self::Grow => Grow::class,
        };
    }

    /** The four ordered setup steps (excludes Grow). */
    public static function setupSteps(): array
    {
        return [self::Business, self::Territory, self::Structure, self::Approve];
    }
}
