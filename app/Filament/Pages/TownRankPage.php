<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsTownPage;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Operator\ActiveTenant;
use App\TownRank\TownRankBoard;
use App\TownRank\TownRankKeywords;
use App\TownRank\TownRankRunAll;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Town Rank (operator, Results menu). Landing: a card wall, one card per scanned keyword (both modes' buckets,
 * movement, a map thumbnail). Click a card → that keyword's board: where the WEBSITE ranks in every covered
 * town, as a town scatter (coloured by organic rank, in either query mode), the bucket summary, a filterable
 * town table, and a per-town panel with who outranks us, the page state, the map-pack rank, and the suggested
 * next actions ({@see TownRankBoard}; the rules live in TownDiagnosis). Operator-only, internal, like the
 * sibling geo surfaces; keywordId/mode/townId are URL-bound so a keyword or a town can be deep-linked.
 *
 * @property-read list<array<string, mixed>> $cards
 * @property-read array<string, mixed>|null $board
 * @property-read list<array{keyword_id: string, query: string, scanned_at: string|null}> $keywords
 * @property-read array<string, mixed>|null $town
 * @property-read list<array<string, mixed>> $visibleRows
 */
class TownRankPage extends Page
{
    use BuildsTownPage;

    /** The table shows at most this many towns at once — the filter narrows it. */
    private const ROW_LIMIT = 120;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationLabel = 'Town Rank';

    protected static ?string $title = 'Town Rank';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

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

    /** The "Add keyword" box on the wall. */
    public string $newKeyword = '';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
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

    /** Track a keyword for Town Rank: it gets a card now and joins the weekly sweep. */
    public function addKeyword(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }
        try {
            $keyword = app(TownRankKeywords::class)->track($site, $this->newKeyword);
        } catch (InvalidArgumentException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();

            return;
        }
        $this->newKeyword = '';
        Notification::make()->success()->title("Tracking “{$keyword->query}”")
            ->body('Run its ranking report from the card, or wait for the Monday sweep.')->send();
    }

    /** Remove a keyword from the wall. Its collected scans are kept — adding it back restores the history. */
    public function removeKeyword(string $id): void
    {
        $site = $this->site();
        $keyword = $site === null ? null : Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($id)->first();
        if ($site === null || $keyword === null) {
            return;
        }
        if ((string) $this->keywordId === (string) $keyword->id) {
            $this->closeKeyword();
        }

        $was = app(TownRankKeywords::class)->untrack($site, $keyword);

        Notification::make()->success()->title("Removed “{$keyword->query}”")
            ->body($was['scans'] > 0
                ? sprintf('Off the wall and out of the weekly sweep. Its %s scan(s) are kept — add the keyword back and the history returns.', number_format($was['scans']))
                : 'Off the wall and out of the weekly sweep.')
            ->send();
    }

    /** "Run ranking report" on a card: post both query modes for the keyword (queued, collected within minutes). */
    /**
     * What a sitewide run would cost, computed the same way the run itself computes it — one plan(), so
     * the number beside the button is the number that gets spent.
     *
     * A keyword already collecting is excluded from both, so an in-flight answer is never bought twice,
     * and one refused by the per-keyword request ceiling is counted apart rather than silently dropped.
     *
     * @return array{towns: int, runnable: int, tracked: int, pending: int, blocked: int, requests: int, cost: float, balance: float|null, affordable: bool}|null
     */
    #[Computed]
    public function sweepPlan(): ?array
    {
        $site = $this->site();
        if ($site === null) {
            return null;
        }

        $plan = app(TownRankRunAll::class)->plan($site);

        return [
            'towns' => $plan['towns'],
            'runnable' => count($plan['runnable']),
            'tracked' => $plan['tracked'],
            'pending' => $plan['pending'],
            'blocked' => $plan['blocked'],
            'requests' => $plan['requests'],
            'cost' => $plan['cost'],
            'balance' => $plan['balance'],
            'affordable' => $plan['affordable'],
        ];
    }

    /**
     * Queue the website rankings for every tracked keyword — one job per keyword, never posted inline.
     *
     * Each keyword's scan covers the site's WHOLE town list, so this runs once for the site rather than
     * once per office: running it per area would re-scan every shared town again for each area.
     */
    public function runAllKeywords(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $result = app(TownRankRunAll::class)->run($site);
        unset($this->sweepPlan);

        if ($result['queued'] === 0) {
            $plan = app(TownRankRunAll::class)->plan($site);
            if (! $plan['affordable']) {
                Notification::make()->danger()->title('Not queued — DataForSEO balance too low')
                    ->body(sprintf('This run needs ~$%s and the account holds $%s. Top up, then run it again; nothing was posted.',
                        number_format($plan['cost'], 2), number_format((float) $plan['balance'], 2)))->send();

                return;
            }
            Notification::make()->warning()->title('Nothing queued')
                ->body('Every tracked keyword is already collecting, or is refused by the request ceiling.')->send();

            return;
        }

        Notification::make()->success()
            ->title(sprintf('Queued %s keyword(s) · %s requests (~$%s)', number_format($result['queued']), number_format($result['requests']), number_format($result['cost'], 2)))
            ->body('One job per keyword; results collect over the next few minutes and each card updates as they land.')->send();
    }

    public function runKeyword(string $id): void
    {
        $site = $this->site();
        $keyword = $site === null ? null : Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($id)->first();
        if ($site === null || $keyword === null) {
            return;
        }
        $result = app(TownRankKeywords::class)->run($site, $keyword);
        if (! $result['queued']) {
            Notification::make()->warning()->title('Not queued')->body((string) $result['reason'])->send();

            return;
        }
        Notification::make()->success()
            ->title(sprintf('Posting %s DataForSEO requests (~$%s) for “%s”', number_format($result['requests']), number_format($result['cost'], 2), $keyword->query))
            ->body('Results collect over the next few minutes; the card updates as they land.')->send();
    }

    /** Card click: open the keyword's board. */
    public function openKeyword(string $id): void
    {
        $this->keywordId = $id;
        $this->townId = null;
    }

    /** Back to the card wall. */
    public function closeKeyword(): void
    {
        $this->keywordId = null;
        $this->townId = null;
        $this->filter = '';
    }

    /** @return list<array<string, mixed>> */
    public function getCardsProperty(): array
    {
        $site = $this->site();

        return $site !== null ? app(TownRankBoard::class)->cards($site) : [];
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

    /** The selected keyword's board; null on the card wall (no keyword selected) or without scans. */
    public function getBoardProperty(): ?array
    {
        $site = $this->site();
        if ($site === null || $this->keywordId === null) {
            return null;
        }

        return app(TownRankBoard::class)->for($site, $this->keywordId, $this->mode);
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
