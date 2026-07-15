<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ManagesPruneSurface;
use App\Filament\Pages\Gathering\SilosStep;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Phase 4 PR-B — the prune surface (operator admin, sibling to Owner Interview). Walks
 * the candidate SiloBlueprint into a confirmed one. The asymmetry of effort made visual:
 * grouped by silo, batch-confirm the stated core, focus on the volume-sorted lean-ins,
 * quick-route the fringe.
 *
 * SUPERSEDED by the new Setup's step 7 ({@see SilosStep}),
 * which hosts the same {@see ManagesPruneSurface} interaction as a mode inside the
 * Silos & keywords surface — prune without silo context is meaningless, so it stopped
 * being its own menu item. Hidden when the new Setup menu is on; the route stays live.
 *
 * @property-read array<string, string> $siteOptions
 * @property-read bool $hasCandidates
 * @property-read list<string> $deadSilos
 * @property-read list<array{id: string, type: string, message: string, score: float|null, spoke: string}> $arrangeFlags
 */
class SiloPrune extends Page
{
    use ManagesPruneSurface;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scissors';

    protected static ?string $navigationLabel = 'Prune';

    protected static string|\UnitEnum|null $navigationGroup = 'Advanced';

    protected static ?int $navigationSort = 3;

    /** Menu-map family tag: superseded by Setup step 7 (Silos & keywords, prune mode within). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_setup_enabled');
    }

    protected string $view = 'filament.pages.silo-prune';

    public ?string $siteId = null;

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    protected function pruneSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }
}
