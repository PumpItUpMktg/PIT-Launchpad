<?php

namespace App\Filament\Pages\Operate;

use App\Enums\RedirectSource;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\OrphanScanner;
use Filament\Notifications\Notification;

/**
 * Operate · Orphans — the deletion safety net. Scans the working tenant with {@see OrphanScanner} and
 * lists what a deletion left behind, each with a one-click fix where one exists:
 *
 *  - MISSING REDIRECT → "Create 301": adds a redirect from the retired URL to its parent hub (or home).
 *  - STRANDED LIVE     → "Take down": removes the still-live WordPress post ({@see DeleteFromWordpress}).
 *  - ORPHANED CHILD    → informational (restore/recreate the parent, or re-home the page).
 *
 * Reached from the Portfolio's "Scan for orphans" action (which sets the tenant and lands here) or the
 * Operate sidebar. Read-until-you-act: the scan itself changes nothing.
 *
 * @property-read list<array<string, mixed>> $findings
 */
class OrphanScan extends OperatePage
{
    protected static ?string $slug = 'operate/orphans';

    protected static ?string $navigationLabel = 'Orphans';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.operate.orphans';

    // Reached from the Portfolio's "Scan for orphans" action, not a daily sidebar item — kept out of
    // the nav so the Operate menu stays the working pages.
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
    public function getFindingsProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? []
            : array_map(fn ($f): array => $f->toArray(), app(OrphanScanner::class)->scan($site));
    }

    public function rescan(): void
    {
        // The findings property recomputes on render; this is just an explicit refresh + confirmation.
        Notification::make()->success()->title('Rescanned')->send();
    }

    /** Add a 301 from a retired URL to its parent hub (or home) — the fix for a missing_redirect finding. */
    public function createRedirect(string $contentId): void
    {
        $content = $this->ownedTrashed($contentId);
        if ($content === null) {
            return;
        }

        $from = '/'.ltrim((string) $content->slug, '/');

        $exists = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereRaw('lower(trim(from_url)) = ?', [mb_strtolower(trim($from))])
            ->where('status', 'active')
            ->exists();
        if ($exists) {
            Notification::make()->warning()->title('Redirect already exists')->body("An active redirect already covers {$from}.")->send();

            return;
        }

        Redirect::withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $this->siteId,
            'from_url' => $from,
            'to_url' => $this->suggestedTarget($content),
            'code' => 301,
            'source' => RedirectSource::SlugChange->value,
            'status' => 'active',
        ]);

        Notification::make()->success()->title('301 created')
            ->body("Redirecting {$from} → {$this->suggestedTarget($content)}. It goes live on the next redirect push; edit the target under Connections if needed.")
            ->send();
    }

    /** Remove the still-live WordPress post for a stranded_live finding. */
    public function takeDown(string $contentId): void
    {
        $content = $this->ownedTrashed($contentId);
        if ($content === null) {
            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body($result['message'])->send();

            return;
        }

        Notification::make()->success()->title('Taken down')
            ->body('Removed from WordPress. If its old URL had traffic, add a 301 next.')->send();
    }

    /** The best redirect target: the retired page's parent hub if it's still live, else the home page. */
    private function suggestedTarget(Content $content): string
    {
        if ($content->parent_content_id !== null) {
            $parent = Content::withoutGlobalScope(SiteScope::class)->find($content->parent_content_id);
            if ($parent !== null && trim((string) $parent->slug) !== '') {
                return '/'.ltrim(trim((string) $parent->slug), '/');
            }
        }

        return '/';
    }

    private function ownedTrashed(string $contentId): ?Content
    {
        return $this->siteId === null ? null : Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $this->siteId)
            ->whereKey($contentId)
            ->first();
    }
}
