<?php

namespace App\Filament\Pages;

use App\Guided\StepGate;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * The single "Setup" menu entry (unified-menu relay): lands the operator on the working site's
 * CURRENT setup step — the step pages themselves are freely tabbable via the in-page rail and no
 * longer clutter the sidebar. Pure redirector; the fallback view renders only when no site exists.
 */
class SetupHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Setup';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'setup';

    /** Menu-map family tag: superseded — the new Setup covers the full arc (gather → generate → launch). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    // The new Setup entry ({@see \App\Filament\Pages\Gathering\SetupEntry}) takes the sidebar
    // slot once the flag is on; this guided flow stays routable until cutover.
    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_setup_enabled');
    }

    protected string $view = 'filament.pages.setup-home';

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site === null) {
            return; // no sites yet — render the empty state
        }

        session(['guided_site_id' => $site->id]);

        $step = app(StepGate::class)->state($site)->step();
        $this->redirect($step->pageClass()::getUrl());
    }
}
