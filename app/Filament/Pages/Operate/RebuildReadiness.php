<?php

namespace App\Filament\Pages\Operate;

use App\ContentEngine\Reconcile\RebuildReadiness as ReadinessModel;
use App\ContentEngine\Reconcile\RebuildReconciler;
use App\Models\Site;
use Filament\Notifications\Notification;

/**
 * Operate · Readiness (§B slice 5) — the per-tenant build-stage checklist. Shows, in dependency order,
 * what's aligned to the current silo tree and what has drifted (unbucketed keywords, unpinned pages,
 * silos missing a WP category, posts on a stale silo, Uncategorized live posts), each amber/red row
 * naming its fix. Two actions run the {@see RebuildReconciler} cascade — "Reconcile" (re-align only) and
 * "Rebuild structure & reconcile" (rewrite structure first). Derived from persisted rows only (no
 * network); the checklist itself changes nothing until you run a cascade.
 *
 * @property-read list<array<string, mixed>> $rows
 */
class RebuildReadiness extends OperatePage
{
    protected static ?string $slug = 'operate/readiness';

    protected static ?string $navigationLabel = 'Readiness';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.operate.readiness';

    // Reached from the Portfolio's "Readiness" action (which sets the tenant and lands here) or a direct
    // link — kept out of the daily Operate nav so the menu stays the working pages boards.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?string $siteId = null;

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getRowsProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? []
            : array_map(fn ($row): array => $row->toArray(), app(ReadinessModel::class)->for($site));
    }

    public function reconcile(bool $structure = false): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $report = app(RebuildReconciler::class)->reconcile($site, $structure);

        $notification = Notification::make()
            ->title($structure ? 'Rebuild & reconcile complete' : 'Reconcile complete')
            ->body($report->summary());

        ($report->ok() ? $notification->success() : $notification->warning())->send();
    }
}
