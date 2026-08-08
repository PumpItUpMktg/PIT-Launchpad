<?php

namespace App\Providers\Filament;

use App\Models\User;
use App\Security\Capability;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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

/**
 * The Operations Console — a THIRD, stand-alone Filament panel (id `console`, path `/console`), separate
 * from the operator cockpit (`/admin`) and the client portal (`/portal`). It exists so the two-tier
 * operating model (internal **Super Admin** vs a client-side **Site Admin**) has its own home WITHOUT
 * touching either existing panel: nothing on the current dashboards changes. Its pages replicate the
 * day-to-day operating features and are capability-gated ({@see Capability}), so a Super
 * Admin simply sees additional controls a Site Admin does not.
 *
 * Access is gated by {@see User::canAccessPanel()} (`console` → Super Admin OR Site Admin).
 */
class ConsolePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('console')
            ->path('console')
            ->login()
            ->brandName('Operations Console')
            ->colors(['primary' => Color::Indigo])
            // Global button/control interaction feedback (hover/press/focus/busy) for every custom control.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('filament.shared.interaction-styles'),
            )
            ->navigationGroups(['Operate', 'Recover', 'Administration'])
            ->discoverPages(in: app_path('Filament/Console/Pages'), for: 'App\Filament\Console\Pages')
            ->discoverResources(in: app_path('Filament/Console/Resources'), for: 'App\Filament\Console\Resources')
            ->discoverWidgets(in: app_path('Filament/Console/Widgets'), for: 'App\Filament\Console\Widgets')
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
            ]);
    }
}
