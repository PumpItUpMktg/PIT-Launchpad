<?php

namespace App\Filament\Pages\Operate;

use App\Operate\PagesBoard;
use Livewire\Attributes\Url;

/**
 * Operate · Pages — the consolidated pages board (Relay 3 · PR 5c). One nav item, three family tabs:
 * **core · service · town** (town = the location-page family). Replaces the three separate boards
 * (Core / Service / Location) — same {@see OperatePagesBoard} lifecycle machinery, one surface, the
 * active tab picks which family's work/live lanes render.
 */
class OperatePages extends OperatePagesBoard
{
    protected static ?string $slug = 'operate/pages';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.operate.pages-board';

    /** The active family tab. Keys are the tab slugs; values are the {@see PagesBoard} family method. */
    public const FAMILIES = ['core' => 'core', 'service' => 'services', 'town' => 'locations'];

    #[Url]
    public string $tab = 'core';

    public function mount(): void
    {
        parent::mount(); // sets the ActiveTenant-locked siteId
        if (! array_key_exists($this->tab, self::FAMILIES)) {
            $this->tab = 'core';
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::FAMILIES)) {
            $this->tab = $tab;
        }
    }

    /** The board family for the active tab (drives {@see OperatePagesBoard::getBoardProperty()}). */
    protected function family(): string
    {
        return self::FAMILIES[$this->tab] ?? 'core';
    }

    /**
     * The consolidated board carries every family's header actions — the on-demand ranking pull (was
     * service-only, but it is a site-level pull) plus the sitemap + IndexNow submits.
     */
    protected function getHeaderActions(): array
    {
        return [$this->refreshRankingsAction(), $this->submitSitemapAction(), $this->pingIndexNowAction()];
    }
}
