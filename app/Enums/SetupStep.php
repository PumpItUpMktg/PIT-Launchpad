<?php

namespace App\Enums;

use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Plan;
use App\Filament\Pages\Guided\WhereYouWork;

/**
 * The unified onboarding flow: five setup steps + the Build & Grow phases. Creating a site
 * enters at Business; each step's {@see prerequisiteFlag()} (the prior step's completion) must be
 * set before its "done" marker can light — the tabs themselves are free to visit.
 *
 * Consolidated from the original seven steps (setup-redesign relay): Territory merged into
 * WhereYouWork (physical locations + the territory each serves, one step), and
 * Structure + Inventory + Approve merged into Plan (cards-first plan page with the structure
 * tree demoted to an adjust panel and Approve as its button, not a step). The persisted
 * `current_step` integers were remapped by migration (5/6/7 → 5, 8 → 6, 9 → 7).
 */
enum SetupStep: int
{
    case Business = 1;
    case ConnectWordpress = 2;
    case Brand = 3;
    case WhereYouWork = 4;
    case Plan = 5;
    case Build = 6;
    case Grow = 7;

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Your business',
            self::ConnectWordpress => 'Connect your website',
            self::Brand => 'Your brand',
            self::WhereYouWork => 'Where you work',
            self::Plan => 'Your website plan',
            self::Build => 'Build',
            self::Grow => 'Grow',
        };
    }

    public function sublabel(): string
    {
        return match ($this) {
            self::Business => 'Services, contact & hours',
            self::ConnectWordpress => 'Link WordPress',
            self::Brand => 'Logo, colors & voice',
            self::WhereYouWork => 'Locations & the towns you serve',
            self::Plan => 'Review the pages & approve',
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
            self::ConnectWordpress => 'services_done',
            self::Brand => 'deps_ready',       // WordPress must be connected + prepped first
            self::WhereYouWork => 'brand_pushed',
            self::Plan => 'territory_done',
            self::Build => 'approved',
            self::Grow => 'launched',
        };
    }

    /**
     * The SetupState boolean this step sets when its work completes (null = nothing to set).
     * `structure_finalized` / `inventory_reviewed` still exist as columns — Plan's approve sets
     * them internally — but no step gates on them anymore.
     */
    public function completionFlag(): ?string
    {
        return match ($this) {
            self::Business => 'services_done',
            self::ConnectWordpress => 'deps_ready',
            self::Brand => 'brand_pushed',
            self::WhereYouWork => 'territory_done',
            self::Plan => 'approved',
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
            self::WhereYouWork => WhereYouWork::class,
            self::Plan => Plan::class,
            // Build is no longer a wizard screen — materialize replaced the build phase. The enum
            // value persists (its 'launched' gate marks the handoff); it resolves to Grow.
            self::Build => Grow::class,
            self::Grow => Grow::class,
        };
    }

    /** The five ordered setup steps (excludes the Build/Grow phases). */
    public static function setupSteps(): array
    {
        return [self::Business, self::ConnectWordpress, self::Brand, self::WhereYouWork, self::Plan];
    }
}
