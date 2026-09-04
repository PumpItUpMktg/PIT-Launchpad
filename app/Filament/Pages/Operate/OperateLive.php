<?php

namespace App\Filament\Pages\Operate;

use App\Guided\LiveBoardPage;
use App\Operate\LiveBoard;
use Livewire\Attributes\Url;

/**
 * Operate · Live — the consolidated published board (Relay 3 · PR 5e). ONE dataset of everything live
 * across every type, with a type selector (All · Blog · Core · Service · Town, each with a count) and a
 * filter row (search · market · not-indexed · not-ranking). Replaces the five per-family published
 * boards. "All" is the default — one place to answer "what's live and what's wrong with it".
 *
 * Reuses {@see LiveBoardPage}'s proven actions (repush / regenerate / take-down) verbatim; the rows come
 * from {@see LiveBoard} (cached metrics, render-budgeted). The legacy per-family Live boards stay as
 * routes (off-nav).
 *
 * @property-read array<string, int> $counts
 * @property-read array<string, string> $marketOptions
 * @property-read list<array<string, mixed>> $rows
 */
class OperateLive extends LiveBoardPage
{
    protected static ?string $slug = 'operate/live';

    protected static ?string $navigationLabel = 'Live';

    protected string $view = 'filament.operate.live';

    public const TYPES = ['all', 'blog', 'core', 'service', 'town'];

    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $market = null;

    #[Url]
    public bool $notIndexed = false;

    #[Url]
    public bool $notRanking = false;

    public function mount(): void
    {
        parent::mount(); // LiveBoardPage: sets the ActiveTenant-locked siteId
        if (! in_array($this->tab, self::TYPES, true)) {
            $this->tab = 'all';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TYPES, true)) {
            $this->tab = $tab;
        }
    }

    /** @return array<string, int> */
    public function getCountsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? ['all' => 0, 'blog' => 0, 'core' => 0, 'service' => 0, 'town' => 0]
            : app(LiveBoard::class)->counts($site);
    }

    /** @return array<string, string> */
    public function getMarketOptionsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(LiveBoard::class)->markets($site);
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(LiveBoard::class)->rows($site, $this->tab, [
            'search' => $this->search,
            'market' => $this->market,
            'not_indexed' => $this->notIndexed,
            'not_ranking' => $this->notRanking,
        ]);
    }
}
