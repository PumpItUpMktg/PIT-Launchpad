<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureTenantSelected;
use App\Http\Middleware\ResolveCurrentSite;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
            // Nav cutover (PR 5b): the sidebar and Filament's auto-registered nav are retired in
            // favour of a bespoke FOUR-COLUMN header (Build · Territory · Results · System, 24 items,
            // no dropdowns) rendered from App\Operator\Nav\ConsoleNav via the TOPBAR_AFTER hook.
            // `topNavigation()` drops the sidebar; `navigation(false)` empties Filament's own nav so
            // only our header shows. Per-surface $navigationGroup props are now vestigial (the header
            // is the single source of the IA); every surface's ROUTE stays registered (discovery),
            // so retired items keep working URLs — they are simply not in the header.
            ->topNavigation()
            ->navigation(false)
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
            // The four-column console header — the operator IA (App\Operator\Nav\ConsoleNav).
            ->renderHook(
                PanelsRenderHook::TOPBAR_AFTER,
                fn (): View => view('filament.operator.console-nav'),
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
