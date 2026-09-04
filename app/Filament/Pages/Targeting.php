<?php

namespace App\Filament\Pages;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\TargetingBoard;
use App\Operator\Coverage\TargetQueue;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Targeting (menu-reorg relay): the silo-cards surface — one card per silo with its keyword
 * targets, viability, and the covered/gap split — replacing the two flat tables as the primary
 * Targeting entry. Promote/demote adjusts the §7b priority override inline; the full keyword
 * and silo tables stay routable as drill-downs (linked from the board, hidden from the nav).
 * Shares the working-site session with Grow/Live.
 *
 * @property-read array{silos: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, unassigned_total: int, threshold: int} $board
 */
class Targeting extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-viewfinder-circle';

    protected static ?string $navigationLabel = 'Silos & keywords';

    protected static string|\UnitEnum|null $navigationGroup = 'Targeting';

    protected static ?string $slug = 'targeting';

    /** Menu-map family tag: superseded by Setup step 7 (Silos & keywords — the generate phase). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_setup_enabled');
    }

    protected string $view = 'filament.pages.targeting';

    public ?string $siteId = null;

    public function mount(): void
    {
        // The working tenant is the locked ActiveTenant (Portfolio / topbar switcher); no per-page selection.
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /**
     * @return array{silos: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, unassigned_total: int, threshold: int}
     */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['silos' => [], 'unassigned' => [], 'unassigned_total' => 0, 'threshold' => 0]
            : app(TargetingBoard::class)->for($site);
    }

    public function promote(string $keywordId): void
    {
        $keyword = $this->ownedKeyword($keywordId);
        if ($keyword !== null) {
            app(TargetQueue::class)->promote($keyword);
        }
    }

    public function demote(string $keywordId): void
    {
        $keyword = $this->ownedKeyword($keywordId);
        if ($keyword !== null) {
            app(TargetQueue::class)->demote($keyword);
        }
    }

    private function ownedKeyword(string $keywordId): ?Keyword
    {
        return $this->siteId === null ? null : Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($keywordId)
            ->first();
    }
}
