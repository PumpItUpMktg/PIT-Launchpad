<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Operator\ActiveTenant;
use App\TownRank\TownRankBoard;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Town Rank (operator) — one page per keyword: where the WEBSITE ranks in every covered town, as a town
 * scatter (coloured by organic rank, in either query mode), the bucket summary, a filterable town table, and
 * a per-town panel with who outranks us, the page state, the map-pack rank, and the suggested next actions
 * ({@see TownRankBoard}; the rules live in TownDiagnosis). Operator-only, internal, like the sibling geo
 * surfaces; keywordId/mode/townId are URL-bound so a town can be deep-linked.
 *
 * @property-read array<string, mixed>|null $board
 * @property-read list<array{keyword_id: string, query: string, scanned_at: string|null}> $keywords
 * @property-read array<string, mixed>|null $town
 * @property-read list<array<string, mixed>> $visibleRows
 */
class TownRankPage extends Page
{
    /** The table shows at most this many towns at once — the filter narrows it. */
    private const ROW_LIMIT = 120;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationLabel = 'Town Rank';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'town-rank';

    protected string $view = 'filament.pages.town-rank';

    public ?string $siteId = null;

    #[Url]
    public ?string $keywordId = null;

    #[Url]
    public string $mode = TownRankScan::MODE_TOWN_QUERY;

    #[Url]
    public ?string $townId = null;

    /** Colour the map by: rank (absolute, default) | move (movement since the previous scan). */
    #[Url]
    public string $colorBy = 'rank';

    public string $filter = '';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
        if (! in_array($this->mode, TownRankScan::MODES, true)) {
            $this->mode = TownRankScan::MODE_TOWN_QUERY;
        }
    }

    public function updatedKeywordId(): void
    {
        $this->townId = null;
    }

    public function selectTown(string $id): void
    {
        $this->townId = $id;
    }

    public function setView(string $colorBy): void
    {
        $this->colorBy = $colorBy === 'move' ? 'move' : 'rank';
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, TownRankScan::MODES, true) ? $mode : TownRankScan::MODE_TOWN_QUERY;
    }

    /** @return list<array{keyword_id: string, query: string, scanned_at: string|null}> */
    public function getKeywordsProperty(): array
    {
        $site = $this->site();

        return $site !== null ? app(TownRankBoard::class)->keywords($site) : [];
    }

    /** @return array<string, mixed>|null */
    public function getBoardProperty(): ?array
    {
        $site = $this->site();

        return $site !== null ? app(TownRankBoard::class)->for($site, $this->keywordId, $this->mode) : null;
    }

    /** @return array<string, mixed>|null */
    public function getTownProperty(): ?array
    {
        $site = $this->site();
        $board = $this->board;
        if ($site === null || $board === null || $this->townId === null) {
            return null;
        }

        return app(TownRankBoard::class)->town($site, (string) $board['keyword_id'], $this->townId);
    }

    /**
     * The town rows for the table: filtered by the search box, capped at ROW_LIMIT.
     *
     * @return list<array<string, mixed>>
     */
    public function getVisibleRowsProperty(): array
    {
        $board = $this->board;
        if ($board === null) {
            return [];
        }
        $needle = mb_strtolower(trim($this->filter));
        $rows = array_values(array_filter(
            $board['rows'],
            fn (array $r): bool => $needle === '' || str_contains(mb_strtolower((string) $r['label']), $needle),
        ));

        return array_slice($rows, 0, self::ROW_LIMIT);
    }

    private function site(): ?Site
    {
        return $this->siteId !== null ? Site::withoutGlobalScopes()->find($this->siteId) : null;
    }
}
