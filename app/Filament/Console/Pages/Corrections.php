<?php

namespace App\Filament\Console\Pages;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\User;
use App\Operate\QueueHealth;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Recover → Corrections: the Super-Admin-only backend-fix surface — "anything the Site Admin
 * could break," gathered in one place. It reuses the existing recovery machinery verbatim:
 * {@see QueueHealth} (worker status + clear failed jobs), the `launchpad:drain-publish` /
 * `launchpad:reset-publish` / `launchpad:reset-render` commands, and adds the one missing correction
 * with no prior UI — Unlock (clear a page's `locked` / `locally_edited` publish protection).
 *
 * Every action re-checks its recover/admin capability, so this page never renders for a Site Admin.
 */
class Corrections extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Corrections';

    protected static string|\UnitEnum|null $navigationGroup = 'Recover';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'corrections';

    protected string $view = 'filament.console.corrections';

    /** Super-Admin-only: gated on a recover capability the Site Admin never holds. */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::UnfreezeQueue);
    }

    /** @return array{pending: int, oldest_minutes: int, failed: int, processing: int, draining: bool, worker_down: bool, stalled: bool} */
    public function getHealthProperty(): array
    {
        return app(QueueHealth::class)->snapshot();
    }

    /** @return list<array{job: string, reason: string, count: int, last: string, pages: list<string>}> */
    public function getFailuresProperty(): array
    {
        return app(QueueHealth::class)->failures();
    }

    /** @return list<array{id: string, title: string, locked: bool, locally_edited: bool}> */
    public function getLockedProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where(fn ($q) => $q->where('locked', true)->orWhere('locally_edited', true))
            ->orderBy('title')
            ->get()
            ->map(fn (Content $c): array => [
                'id' => (string) $c->id,
                'title' => (string) $c->title,
                'locked' => (bool) $c->locked,
                'locally_edited' => (bool) $c->locally_edited,
            ])
            ->all();
    }

    /** Delete every failed_jobs row (queue:flush) so a stalled banner clears. */
    public function clearFailed(): void
    {
        if (! $this->can(Capability::UnfreezeQueue)) {
            return;
        }
        $count = app(QueueHealth::class)->clearFailed();

        Notification::make()->title("Cleared {$count} failed job(s).")->success()->send();
    }

    /** Force the in-flight publishes for this site through synchronously (no worker needed). */
    public function drain(): void
    {
        if (! $this->can(Capability::UnfreezeQueue) || $this->siteId === null) {
            return;
        }
        Artisan::call('launchpad:drain-publish', ['site' => $this->siteId]);

        Notification::make()->title('Drained the publish backlog for this site.')->success()->send();
    }

    /** Clear stuck rendering/publishing/failed content states for this site (and flush its dead jobs). */
    public function resetPublishing(): void
    {
        if (! $this->can(Capability::ResetPublishing) || $this->siteId === null) {
            return;
        }
        Artisan::call('launchpad:reset-publish', ['site' => $this->siteId, '--flush-failed' => true]);

        Notification::make()->title('Reset stuck publishing for this site.')->success()->send();
    }

    /** Requeue every render_failed image (platform-wide — the command has no site filter). */
    public function resetRenders(): void
    {
        if (! $this->can(Capability::ResetRenders)) {
            return;
        }
        Artisan::call('launchpad:reset-render');

        Notification::make()->title('Requeued failed image renders.')->success()->send();
    }

    /**
     * Re-push this site's universal header/footer chrome (brand + NAP + the nav menu) to WordPress.
     * The chrome is site-wide, so it never rides a page push — this is how a corrected menu (e.g. after
     * the header nav is fixed) reaches the live site. Reuses {@see SyncSiteProfileCommand} verbatim.
     */
    public function syncChrome(): void
    {
        if (! $this->can(Capability::ResetPublishing) || $this->siteId === null) {
            return;
        }
        $code = Artisan::call('launchpad:sync-site-profile', ['site' => $this->siteId]);

        $code === 0
            ? Notification::make()->title('Header & footer synced to WordPress.')->success()->send()
            : Notification::make()->title('Chrome sync failed')->body(trim(Artisan::output()) ?: 'The plugin rejected the push.')->danger()->send();
    }

    /**
     * Re-push every PUBLISHED post + page for this site to WordPress (refreshes the rendered body,
     * canonical/og/schema, and slot content). Throttled into waves by the command; idempotent by ULID,
     * so it updates the existing WordPress posts rather than duplicating. The fix-all after a content
     * or template change that didn't ride an individual republish.
     */
    public function syncPages(): void
    {
        if (! $this->can(Capability::ResetPublishing) || $this->siteId === null) {
            return;
        }
        $code = Artisan::call('launchpad:repush-published', ['--site' => $this->siteId, '--kind' => 'all']);

        $code === 0
            ? Notification::make()->title('Re-pushing all published pages & posts (throttled into waves).')->success()->send()
            : Notification::make()->title('Page re-push failed')->body(trim(Artisan::output()) ?: 'Could not queue the re-push.')->danger()->send();
    }

    /**
     * Re-push this site's silo tree to WordPress categories (roots-first, idempotent by ULID; renames
     * any "Silo {ulid}" placeholder), and re-apply the fixed category to live posts. The fix for a
     * category that never synced or shows the placeholder name.
     */
    public function syncSilos(): void
    {
        if (! $this->can(Capability::ResetPublishing) || $this->siteId === null) {
            return;
        }
        $code = Artisan::call('launchpad:sync-silo-categories', ['site' => $this->siteId, '--repush-content' => true]);

        $code === 0
            ? Notification::make()->title('Silo categories synced to WordPress.')->success()->send()
            : Notification::make()->title('Silo sync failed')->body(trim(Artisan::output()) ?: 'The plugin rejected the category push.')->danger()->send();
    }

    /** Clear a page's publish protection so a re-publish can overwrite it again. */
    public function unlock(string $contentId): void
    {
        if (! $this->can(Capability::UnlockPage)) {
            return;
        }
        $content = $this->ownedContent($contentId);
        if ($content === null) {
            return;
        }

        $content->forceFill(['locked' => false, 'locally_edited' => false])->save();

        Notification::make()->title('Unlocked — re-publish can update it now.')->success()->send();
    }

    /** Whether this site currently has anything mid-flight worth draining. */
    public function hasInFlight(): bool
    {
        if ($this->siteId === null) {
            return false;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereIn('status', [ContentStatus::Approved->value, ContentStatus::Rendering->value, ContentStatus::Publishing->value])
            ->exists();
    }
}
