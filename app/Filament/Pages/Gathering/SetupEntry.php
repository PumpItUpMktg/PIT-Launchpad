<?php

namespace App\Filament\Pages\Gathering;

use App\Gathering\SetupProgress;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * The ONE "Setup" menu entry (menu cleanup): the nine step pages leave the sidebar — this
 * lands the operator on the RESUME step (first unfinished required step; a fully-done setup
 * lands on Launch) and the in-page stepper rail navigates from there. Run once, return any
 * time. Flag-gated like the rest of the parallel build.
 */
class SetupEntry extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Setup';

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'setup2';

    protected string $view = 'filament.gathering.setup-entry';

    // Nav-final: the SINGLE top-level "Setup" item (the nine step pages no longer register).
    // Opening it resumes at the first unfinished required step; the in-page rail carries the rest.
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('launchpad.new_setup_enabled');
    }

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site === null) {
            return; // no tenants yet — render the empty state
        }

        session(['guided_site_id' => $site->id]);
        $this->redirect(app(SetupProgress::class)->resumeStep($site)::getUrl(), navigate: true);
    }
}
