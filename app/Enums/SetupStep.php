<?php

namespace App\Enums;

use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Build;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Inventory;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Guided\Territory;

/**
 * The guided setup flow: five setup steps + the Build & Grow phases. The order is the pipeline's
 * dependency order made structural — each step's {@see prerequisiteFlag()} (the prior step's
 * completion) must be set before it unlocks, so territory can't be skipped ahead of structure
 * and volume always grounds against a real territory. {@see completionFlag()} is the gate a
 * step sets when its work is done. Page Inventory is a read-only review step between Structure
 * and Approve — it shares Approve's prerequisite (no new gate column) and is a pass-through.
 */
enum SetupStep: int
{
    case Business = 1;
    case Territory = 2;
    case Structure = 3;
    case Inventory = 4;
    case Approve = 5;
    case Build = 6;
    case Grow = 7;

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business & services',
            self::Territory => 'Territory',
            self::Structure => 'Structure',
            self::Inventory => 'Page inventory',
            self::Approve => 'Approve & build',
            self::Build => 'Build',
            self::Grow => 'Grow',
        };
    }

    public function sublabel(): string
    {
        return match ($this) {
            self::Business => 'What you do',
            self::Territory => 'Where you work',
            self::Structure => 'Your pages',
            self::Inventory => 'What gets built',
            self::Approve => 'Go live',
            self::Build => 'Going live',
            self::Grow => 'Your live site',
        };
    }

    /** The "Step N of 5" eyebrow; Build and Grow are post-setup phases. */
    public function eyebrow(): string
    {
        return $this->isPhase() ? $this->label() : "Step {$this->value} of 5";
    }

    public function isGrow(): bool
    {
        return $this === self::Grow;
    }

    /** Post-setup phases (not one of the five numbered setup steps). */
    public function isPhase(): bool
    {
        return $this === self::Build || $this === self::Grow;
    }

    /** The SetupState boolean that must be true for this step to unlock (null = always open). */
    public function prerequisiteFlag(): ?string
    {
        return match ($this) {
            self::Business => null,
            self::Territory => 'services_done',
            self::Structure => 'territory_done',
            self::Inventory => 'structure_finalized',
            self::Approve => 'structure_finalized', // shares Inventory's gate — Inventory is a pass-through review
            self::Build => 'approved',
            self::Grow => 'launched',
        };
    }

    /** The SetupState boolean this step sets when its work completes (null = nothing to set). */
    public function completionFlag(): ?string
    {
        return match ($this) {
            self::Business => 'services_done',
            self::Territory => 'territory_done',
            self::Structure => 'structure_finalized',
            self::Inventory => null, // pass-through: advances current_step, sets no gate (Approve already unlocked)
            self::Approve => 'approved',
            self::Build => 'launched',
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
            self::Inventory => Inventory::class,
            self::Approve => Approve::class,
            self::Build => Build::class,
            self::Grow => Grow::class,
        };
    }

    /** The five ordered setup steps (excludes the Build/Grow phases). */
    public static function setupSteps(): array
    {
        return [self::Business, self::Territory, self::Structure, self::Inventory, self::Approve];
    }
}
