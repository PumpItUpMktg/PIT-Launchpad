<?php

namespace App\Filament\Pages;

use App\Enums\ContentKind;
use App\Jobs\ComputeStuckPages;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexStandings;
use App\Operator\Coverage\IndexWatchlist;
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

    /** The stuck page whose "Why?" panel is open (content id), or null — in the URL so a plain link opens it. */
    #[Url(as: 'why')]
    public ?string $whyId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
        // Arrived by a plain ?why= link (the Why? button is a real link, so it works whatever the state of
        // the page's Livewire wiring): queue the diagnosis if nothing is cached, exactly as a click would.
        if ($this->whyId !== null && $this->siteId !== null && $this->getStuckProperty() === null) {
            ComputeStuckPages::request($this->siteId);
        }
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

    /**
     * The stuck pages (past the stuck window) with their reason, inbound links, impressions, lever and
     * recommendation — keyed by content id for the "Why?" panel. A pure READ of what {@see ComputeStuckPages}
     * parked in the cache: the report builds the whole site's link graph and is far too slow for a web
     * request, so it is computed on the queue (after every index sync, and on demand from {@see explain()}).
     * Null = nothing computed yet for this site.
     *
     * @return array{rows: array<string, array<string, mixed>>, computed_at: string}|null
     */
    public function getStuckProperty(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }
        $cached = Cache::get(ComputeStuckPages::cacheKey($this->siteId));

        return is_array($cached) && isset($cached['rows']) ? $cached : null;
    }

    /** Whether a stuck-page computation is queued/running for this site (the panel polls while it is). */
    public function getStuckPendingProperty(): bool
    {
        return $this->siteId !== null && Cache::has(ComputeStuckPages::pendingKey($this->siteId));
    }

    /**
     * Open (or close) the "Why isn't this indexed?" panel for one stuck page. With nothing computed yet for
     * the site, queue the computation — the panel shows "working it out" and fills in when the job lands.
     */
    public function explain(string $contentId): void
    {
        $this->whyId = $this->whyId === $contentId ? null : $contentId;
        if ($this->whyId !== null && $this->siteId !== null && $this->getStuckProperty() === null) {
            ComputeStuckPages::request($this->siteId);
        }
    }

    /** Recompute the report now (the cached one is stale after edits / re-pushes). */
    public function refreshStuck(): void
    {
        if ($this->siteId === null) {
            return;
        }
        Cache::forget(ComputeStuckPages::cacheKey($this->siteId));
        ComputeStuckPages::request($this->siteId);
        Notification::make()->success()->title('Recomputing')->body('The stuck-page diagnosis is being rebuilt on the queue — the panel fills in when it lands.')->send();
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
        Cache::forget(ComputeStuckPages::cacheKey((string) $this->siteId));
        ComputeStuckPages::request((string) $this->siteId);
        Notification::make()->success()->title('Taken down')
            ->body("'{$content->title}' was removed from WordPress and moved back to Candidates. It leaves this list on the next refresh.")->send();
    }

    /**
     * The page's own URL with the watchlist state (sort, dir, open panel) as query — the column headers and
     * the Why? buttons are plain links built from this, so they never depend on a Livewire click landing.
     *
     * @param  array<string, string|null>  $overrides
     */
    public function watchUrl(array $overrides = []): string
    {
        $query = array_filter(array_merge(
            ['sort' => $this->watchSort, 'dir' => $this->watchDir, 'why' => $this->whyId],
            $overrides,
        ), fn ($v): bool => $v !== null && $v !== '');

        return static::getUrl($query);
    }

    /** The link a column header carries: sort by it, or flip the direction when it is already the sort. */
    public function sortUrl(string $column): string
    {
        $flip = $this->watchSort === $column && $this->watchDir === 'asc' ? 'desc' : 'asc';

        return $this->watchUrl(['sort' => $column, 'dir' => $this->watchSort === $column ? $flip : 'asc']);
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
