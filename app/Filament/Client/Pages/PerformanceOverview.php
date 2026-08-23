<?php

namespace App\Filament\Client\Pages;

use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Client\Dashboard\ClientDashboard;
use App\Client\Dashboard\Frame;
use App\Client\Dashboard\LaunchWindow;
use App\Client\Dashboard\Sparkline;
use App\Jobs\RefreshSiteDashboard;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

/**
 * The client's performance landing (§ Client Dashboard v1, PR 6b) — the white-labeled `/portal` home each
 * tenant sees first. Extends Filament's Dashboard so it owns the panel index route, but renders a custom
 * view over the spine read-model ({@see ClientDashboard}) instead of the widget grid.
 *
 * Movement-first and honest by construction: it shows what changed (pages working, keywords improved, pages
 * Google added), trends, standings and observed milestones — no ROI, no attribution, and it reads ONLY from
 * the metric spine. The existing §7c widgets live on the {@see Insights} page.
 */
class PerformanceOverview extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Performance';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.client.pages.performance-overview';

    /** Switcher selection (owned sites only). */
    public ?string $siteId = null;

    /** Active time frame key: since_launch (default) | last_28. */
    public string $frameKey = 'since_launch';

    public function mount(): void
    {
        $this->siteId = app(ClientContext::class)->site()?->id;
    }

    public function updatedSiteId(): void
    {
        // Mirror the §7c switcher — the session selection drives ClientContext across the panel.
        session(['client_site_id' => $this->siteId]);
    }

    public function setFrame(string $key): void
    {
        $this->frameKey = $key;
    }

    public function getTitle(): string
    {
        return 'Performance';
    }

    /** Suppress Filament's default page header — the Blade renders a brand-led header instead. */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * A one-click "Refresh data" (the brand-header button) — dispatches {@see RefreshSiteDashboard} for the
     * site in view. Operator-only (platform super-user): a real client never triggers expensive external
     * syncs; their data refreshes on the nightly schedule.
     */
    public function refreshData(): void
    {
        if (! (Auth::user()?->isPlatformSuperUser() ?? false)) {
            return;
        }

        $site = $this->resolveSite();
        if ($site === null) {
            Notification::make()->title('No site selected')->warning()->send();

            return;
        }

        RefreshSiteDashboard::dispatch($site->id);
        Notification::make()
            ->title('Refresh started')
            ->body('Rebuilding this dashboard from the latest Search Console, index and ranking data — it will update in a few minutes.')
            ->success()
            ->send();
    }

    /** Custom view renders the spine dashboard, not the widget grid. */
    public function getWidgets(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $site = $this->resolveSite();
        $branding = app(ClientContext::class)->branding();
        $siteOptions = $this->siteOptions();
        $canRefresh = Auth::user()?->isPlatformSuperUser() ?? false;

        if ($site === null) {
            return ['ready' => false, 'branding' => $branding, 'siteOptions' => $siteOptions, 'siteId' => $this->siteId, 'canRefresh' => $canRefresh];
        }

        $frames = app(LaunchWindow::class)->frames($site);
        $frame = $frames[$this->frameKey] ?? reset($frames);
        $dash = app(ClientDashboard::class);

        $visibility = $dash->visibility($site, $frame);

        return [
            'ready' => true,
            'branding' => $branding,
            'siteOptions' => $siteOptions,
            'siteId' => $this->siteId,
            'canRefresh' => $canRefresh,
            'frames' => array_map(fn (Frame $f): string => $f->label, $frames),
            'frameKey' => $frame->key,
            'hero' => $dash->hero($site, $frame),
            'visibility' => $visibility,
            'standings' => $dash->standings($site, $frame),
            'milestones' => $dash->milestones($site),
            'meta' => $dash->meta($site, $frame),
            'charts' => [
                'visibility' => $this->visibilityChart($visibility),
            ],
        ];
    }

    private function resolveSite(): ?Site
    {
        $user = app(ClientContext::class)->user();

        return $user !== null ? app(ClientAccess::class)->currentSite($user, $this->siteId) : null;
    }

    /** @return array<string, string> */
    private function siteOptions(): array
    {
        $user = app(ClientContext::class)->user();
        if ($user === null) {
            return [];
        }

        return app(ClientAccess::class)->sites($user)
            ->mapWithKeys(fn (Site $s): array => [$s->id => (string) ($s->brand_name ?? $s->domain_url ?? $s->id)])
            ->all();
    }

    /**
     * @param  array{series: list<array{date: string, impressions: int, clicks: int}>, markers: list<array{date: string, label: string}>}  $visibility
     * @return array<string, mixed>
     */
    private function visibilityChart(array $visibility): array
    {
        $w = 620;
        $h = 200;
        $impr = array_map(fn (array $r): int => $r['impressions'], $visibility['series']);
        $clicks = array_map(fn (array $r): int => $r['clicks'], $visibility['series']);
        $max = $impr === [] ? 0 : (float) max($impr);
        $dates = array_map(fn (array $r): string => $r['date'], $visibility['series']);

        $markers = [];
        $n = count($dates);
        foreach ($visibility['markers'] as $m) {
            $i = array_search($m['date'], $dates, true);
            if ($i !== false) {
                $markers[] = ['x' => $n <= 1 ? $w : ($i / ($n - 1)) * $w, 'label' => $m['label']];
            }
        }

        return [
            'imprArea' => Sparkline::areaPath($impr, $w, $h, $max),
            'imprLine' => Sparkline::points($impr, $w, $h, $max),
            'clickLine' => Sparkline::points($clicks, $w, $h, $max),
            'markers' => $markers,
            'has_data' => $impr !== [],
        ];
    }
}
