<?php

namespace App\Filament\Pages;

use App\Enums\ContentKind;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexStandings;
use App\Operator\Coverage\IndexWatchlist;
use App\Operator\Coverage\StuckPages;
use App\Publishing\DeleteFromWordpress;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

/**
 * Indexing (operator) — Google index coverage for the tenant, split two ways that keep the number
 * honest: the pages Launchpad **published** (in our sitemap) vs **all** URLs Google knows about
 * (including WP archives it merely found), each with a per-reason breakdown of what isn't indexed.
 *
 * Tenant-locked (reads {@see ActiveTenant}, no per-page site picker), operator-only. Read-only and
 * HTTP-free: everything is a persisted read from `page_index_states` via {@see IndexStandings} — no
 * live GSC / URL-Inspection call at render (that provider sits behind the `sandhog:sync-index` capture).
 *
 * @property-read array{published: array<string, mixed>, all_known: array<string, mixed>, discovered_only: int} $board
 * @property-read array{rows: list<array<string, mixed>>, waiting: int, inspected: int, landed: int, watch_days: int} $watchlist
 */
class IndexingBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Indexing';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'indexing';

    protected string $view = 'filament.pages.indexing-board';

    public ?string $siteId = null;

    /** Watchlist sort column ({@see IndexWatchlist::SORTS}) and direction — kept in the URL so a refresh holds them. */
    #[Url(as: 'sort')]
    public string $watchSort = 'status';

    #[Url(as: 'dir')]
    public string $watchDir = 'asc';

    /** The stuck page whose "Why?" panel is open (content id), or null. */
    public ?string $whyId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /**
     * "Re-check indexing now" — an on-demand run of the same bounded inspection the daily sync does.
     *
     * It costs no money and spends GSC URL-Inspection QUOTA, which is the scarcer thing: Google allows a
     * couple of thousand inspections per property per day, and the run stops at
     * `launchpad.metrics.index_budget_seconds` and falls back to cached verdicts for the rest. A large
     * site therefore completes over several runs rather than in one, and pressing this twice in a row
     * mostly re-reads the cache.
     *
     * Queued, not inline: the inspection is minutes of HTTP against Google and has no business holding a
     * web request open.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheckIndexing')
                ->label('Re-check indexing now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Re-inspect published URLs with Search Console')
                ->modalDescription('Runs the same bounded URL Inspection the daily sync runs. No cost in credits; it spends Search Console inspection quota, stops after about '.max(1, (int) round((float) config('launchpad.metrics.index_budget_seconds', 240) / 60)).' minute(s) of live checks, and uses cached verdicts beyond that. A large site finishes over several runs.')
                ->modalSubmitActionLabel('Yes, re-check now')
                ->action(fn () => $this->recheckIndexing()),
        ];
    }

    private function recheckIndexing(): void
    {
        $site = $this->siteId === null ? null : Site::query()->whereKey($this->siteId)->first();
        if ($site === null) {
            Notification::make()->warning()->title('No site selected')->send();

            return;
        }

        // The same range shape the scheduled sync passes; the index provider inspects current URLs and
        // does not read the window, but the job's contract takes one.
        $today = Carbon::today();
        SyncSiteMetrics::dispatch(
            (string) $site->id,
            IndexMetricProvider::PROVIDER,
            $today->toDateString(),
            $today->toDateString(),
        );

        Notification::make()->success()
            ->title('Re-checking indexing with Search Console')
            ->body('Queued. Verdicts update on this board as URLs are inspected — refresh in a few minutes.')
            ->send();
    }

    public function getTitle(): string
    {
        return 'Indexing';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
    }

    /** @return array{published: array<string, mixed>, all_known: array<string, mixed>, discovered_only: int} */
    public function getBoardProperty(): array
    {
        return app(IndexStandings::class)->for($this->siteId);
    }

    /**
     * The watchlist: every published page not yet indexed (with its publish date), plus the pages that
     * landed in the last few days.
     *
     * @return array{rows: list<array<string, mixed>>, waiting: int, inspected: int, landed: int, watch_days: int}
     */
    public function getWatchlistProperty(): array
    {
        $site = $this->siteId === null ? null : Site::query()->whereKey($this->siteId)->first();

        return app(IndexWatchlist::class)->for($site, $this->watchSort, $this->watchDir);
    }

    /** How long one site's stuck-page report is reused between "Why?" clicks (seconds). */
    private const STUCK_CACHE_SECONDS = 600;

    /**
     * The stuck pages (past the stuck window) with their reason, inbound links, impressions, lever and
     * recommendation — keyed by content id for the "Why?" panel. Only read when a panel is open, and cached
     * per site for ten minutes: the report builds the whole site's internal-link graph (every published
     * body parsed for links), which on a few hundred pages is seconds of work — one compute serves every
     * click on the board. A take-down clears it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getStuckProperty(): array
    {
        $site = $this->siteId === null ? null : Site::query()->whereKey($this->siteId)->first();
        if ($site === null) {
            return [];
        }

        return Cache::remember(
            self::stuckCacheKey((string) $site->id),
            self::STUCK_CACHE_SECONDS,
            fn (): array => collect(app(StuckPages::class)->for($site)['rows'])->keyBy('content_id')->all(),
        );
    }

    public static function stuckCacheKey(string $siteId): string
    {
        return 'indexing:stuck-pages:'.$siteId;
    }

    /** Open (or close) the "Why isn't this indexed?" panel for one stuck page. */
    public function explain(string $contentId): void
    {
        $this->whyId = $this->whyId === $contentId ? null : $contentId;
    }

    /**
     * Take a stuck blog POST off WordPress (the "drop" recommendation) — the same take-down the Posts board
     * offers: §2's delete by ULID, then the row goes back to Candidates. Only a post, only in the locked
     * tenant; a page is never dropped from here.
     */
    public function takeDownPost(string $contentId): void
    {
        $content = $this->siteId === null ? null : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Post->value)
            ->whereKey($contentId)
            ->first();
        if ($content === null) {
            Notification::make()->warning()->title('Only a blog post can be dropped from here')->send();

            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body($result['message'])->send();

            return;
        }

        $this->whyId = null;
        Cache::forget(self::stuckCacheKey((string) $this->siteId));
        Notification::make()->success()->title('Taken down')
            ->body("'{$content->title}' was removed from WordPress and moved back to Candidates. It leaves this list on the next refresh.")->send();
    }

    /** Click a watchlist column header: sort by it; click it again to flip the direction. */
    public function sortWatch(string $column): void
    {
        if (! in_array($column, IndexWatchlist::SORTS, true)) {
            return;
        }
        if ($this->watchSort === $column) {
            $this->watchDir = $this->watchDir === 'asc' ? 'desc' : 'asc';

            return;
        }
        $this->watchSort = $column;
        $this->watchDir = 'asc';
    }
}
