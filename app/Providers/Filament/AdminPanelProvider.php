<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureTenantSelected;
use App\Http\Middleware\ResolveCurrentSite;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // The menu (reorg relay): ungrouped items float on top by sort — Overview, Portfolio,
            // Setup, Grow (the pages workbench, kept clear of the blog pipeline) — then the
            // daily-loop groups: Local Blog (the news/relevance pipeline) → Live (published cards)
            // → Targeting → Settings, with the deep structure tools in a collapsed Advanced group.
            ->navigationGroups([
                // The final IA (flags on): Setup steps 1-9, then Operate, then Advanced.
                // Legacy groups below only render while their items still register (flags off).
                NavigationGroup::make('Setup'),
                NavigationGroup::make('Operate'),
                NavigationGroup::make('Local Blog'),
                NavigationGroup::make('Live Pages'),
                NavigationGroup::make('Targeting'),
                NavigationGroup::make('Settings'),
                NavigationGroup::make('Advanced')->collapsed(),
            ])
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            // The active-tenant banner — the operator's current working tenant (logo + name) shown
            // prominently on EVERY admin page, with a Switch link back to the Portfolio picker.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.operator.tenant-banner'),
            )
            // Global button/control interaction feedback (hover/press/focus/busy) for every custom
            // control across the panel — one sheet, prefix-based, fills every family. See the view.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('filament.shared.interaction-styles'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // The landing is the per-site Overview (App\Filament\Pages\Overview, slug '/') — the
            // old pooled-across-tenants Dashboard is retired; pipeline metrics render only per-site
            // (App\Filament\Pages\SiteCockpit).
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Hard tenant gate: an operator with no active tenant is sent to the Portfolio picker.
                EnsureTenantSelected::class,
                // Bind the resolved tenant into CurrentSite so SiteScope is live in /admin — runs AFTER
                // the gate so a single-site operator's auto-select is already in the session to read.
                ResolveCurrentSite::class,
            ]);
    }
}
