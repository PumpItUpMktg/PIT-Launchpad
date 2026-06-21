<?php

namespace App\Enums;

use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\Build;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Inventory;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Guided\Territory;

/**
 * The unified onboarding flow: seven setup steps + the Build & Grow phases. Creating a site
 * enters at Business; each step's {@see prerequisiteFlag()} (the prior step's completion) must be
 * set before it unlocks — so WordPress is connected + prepped (step 2) before Brand can push
 * (step 3), and the brand push structurally can't run against an unprepared site.
 */
enum SetupStep: int
{
    case Business = 1;
    case ConnectWordpress = 2;
    case Brand = 3;
    case Territory = 4;
    case Structure = 5;
    case Inventory = 6;
    case Approve = 7;
    case Build = 8;
    case Grow = 9;

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business & services',
            self::ConnectWordpress => 'Connect WordPress',
            self::Brand => 'Brand',
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
            self::ConnectWordpress => 'Prep your site',
            self::Brand => 'Look & feel',
            self::Territory => 'Where you work',
            self::Structure => 'Your pages',
            self::Inventory => 'What gets built',
            self::Approve => 'Go live',
            self::Build => 'Going live',
            self::Grow => 'Your live site',
        };
    }

    /** The "Step N of 7" eyebrow; Build and Grow are post-setup phases. */
    public function eyebrow(): string
    {
        return $this->isPhase() ? $this->label() : "Step {$this->value} of 7";
    }

    public function isGrow(): bool
    {
        return $this === self::Grow;
    }

    /** Post-setup phases (not one of the seven numbered setup steps). */
    public function isPhase(): bool
    {
        return $this === self::Build || $this === self::Grow;
    }

    /** The SetupState boolean that must be true for this step to unlock (null = always open). */
    public function prerequisiteFlag(): ?string
    {
        return match ($this) {
            self::Business => null,
            self::ConnectWordpress => 'services_done',
            self::Brand => 'deps_ready',           // WordPress must be connected + prepped first
            self::Territory => 'brand_pushed',
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
            self::ConnectWordpress => 'deps_ready',
            self::Brand => 'brand_pushed',
            self::Territory => 'territory_done',
            self::Structure => 'structure_finalized',
            self::Inventory => 'inventory_reviewed', // Continue = reviewed; completes the step (Approve already unlocked via structure_finalized)
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
            self::ConnectWordpress => ConnectWordpress::class,
            self::Brand => Brand::class,
            self::Territory => Territory::class,
            self::Structure => Structure::class,
            self::Inventory => Inventory::class,
            self::Approve => Approve::class,
            self::Build => Build::class,
            self::Grow => Grow::class,
        };
    }

    /** The seven ordered setup steps (excludes the Build/Grow phases). */
    public static function setupSteps(): array
    {
        return [self::Business, self::ConnectWordpress, self::Brand, self::Territory, self::Structure, self::Inventory, self::Approve];
    }
}
