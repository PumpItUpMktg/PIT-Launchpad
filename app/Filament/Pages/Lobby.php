<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Filament\Pages\Gathering\SetupEntry;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\ReviewCaptureResource;
use App\Filament\Resources\SourceResource;
use App\Http\Middleware\EnsureTenantSelected;
use App\Operator\ActiveTenant;
use App\Operator\Lobby\LobbyBoard;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * The operator lobby — the no-tenant surface: pick a tenant to work on, or triage which tenants need
 * attention. Read-only and cross-tenant by construction; no mutation is reachable here. Entering a
 * tenant (a card body or a badge) locks it via {@see ActiveTenant} and redirects in the SAME action, so
 * work always happens inside exactly one locked tenant.
 *
 * All data comes from {@see LobbyBoard} in a single aggregated pass — no per-card query or HTTP call.
 * Arriving here clears any locked tenant (this IS "no site selected"), so the gate must allowlist it
 * ({@see EnsureTenantSelected}) — it's reachable with no tenant, like the Portfolio.
 */
class Lobby extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Lobby';

    protected static ?int $navigationSort = -20;

    protected static ?string $slug = 'lobby';

    protected string $view = 'filament.pages.lobby';

    /** Server-side search over brand name + domain. */
    public string $search = '';

    /** all | attention | onboarding. */
    public string $filter = 'all';

    /** cards | rows. */
    public string $density = 'cards';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Operator;
    }

    public function getTitle(): string
    {
        return 'Lobby';
    }

    public function mount(): void
    {
        // The lobby is the no-tenant surface — leaving any locked tenant so cross-tenant triage is honest.
        app(ActiveTenant::class)->clear();
    }

    /** The assembled cards for the current search/filter — one aggregated pass, no per-card query. */
    public function getCardsProperty(): Collection
    {
        return app(LobbyBoard::class)->cards($this->search, $this->filter);
    }

    /** Enter a tenant (card body / "+N more") — lock it and open its dashboard, in one action. */
    public function enter(string $siteId): void
    {
        app(ActiveTenant::class)->set($siteId);
        $this->redirect(TenantDashboard::getUrl());
    }

    /** Enter a tenant AT the surface a badge points to — lock it and open the filtered surface. */
    public function enterBadge(string $siteId, string $badgeKey): void
    {
        app(ActiveTenant::class)->set($siteId);
        $this->redirect($this->badgeUrl($badgeKey));
    }

    /**
     * Map a badge key to the tenant surface it links to. Surfaces not yet built (a Markets workspace, the
     * cross-panel Job review) fall back to the dashboard — still one click into the locked tenant.
     */
    private function badgeUrl(string $key): string
    {
        return match ($key) {
            'wp_connection' => ConnectionsResource::getUrl('index'),
            'wrong_nap' => CitationsBoard::getUrl(),
            'feeds_bad' => SourceResource::getUrl('index'),
            'reviews_pending', 'reviews_no_market' => ReviewCaptureResource::getUrl('index'),
            'publish_failed', 'render_failed', 'pages_review', 'blog_review' => ContentReviewResource::getUrl('index'),
            'starved_queues' => OperateBlog::getUrl(),
            'setup_gaps' => SetupEntry::getUrl(),
            'chrome_stale', 'chrome_never_synced' => RebuildReadiness::getUrl(), // Recover → Push chrome
            default => TenantDashboard::getUrl(), // held_market (Markets → PR 5), jobs_review (console), coverage_overdue
        };
    }
}
