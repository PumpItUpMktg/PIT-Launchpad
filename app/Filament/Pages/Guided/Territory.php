<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Filament\Pages\LocationsSetup;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;

/**
 * Step 2 · Territory. Wraps the existing locations layer (county select + 4-tier towns +
 * page_selected) inside the stepper + gating: it surfaces the service-area summary (home county
 * + counties served) and routes to the detailed {@see LocationsSetup} picker
 * for the suggest-then-confirm county pre-fill and town tiers. Continue → territory_done.
 * (The full inline port of the town tiers is left to the site-wide cohesion pass.)
 *
 * @property-read array{counties: int, home: string|null, has_location: bool} $territory
 */
class Territory extends GuidedPage
{
    protected static ?string $slug = 'setup/territory';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Territory';

    protected string $view = 'filament.guided.territory';

    public function step(): SetupStep
    {
        return SetupStep::Territory;
    }

    /**
     * @return array{counties: int, home: string|null, has_location: bool}
     */
    public function getTerritoryProperty(): array
    {
        $site = $this->getSite();
        $location = $site === null ? null : Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->first();

        return [
            'counties' => $location !== null && is_array($location->county_geoids) ? count($location->county_geoids) : 0,
            'home' => $location?->home_county_geoid,
            'has_location' => $location !== null,
        ];
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Territory);

        Notification::make()->title('Territory saved.')->success()->send();
        $this->redirect(SetupStep::Structure->pageClass()::getUrl());
    }
}
