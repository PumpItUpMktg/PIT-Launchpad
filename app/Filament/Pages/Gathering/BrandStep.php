<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\ConnectionProvider;
use App\Filament\Concerns\ManagesBrandKit;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\SiteNarrative;
use App\Styling\StyleVariation;
use Livewire\WithFileUploads;

/**
 * New Setup · Step 7 — Brand: the look + the words. Pick a style (one of three theme.json
 * variations, voice-recommended, or the logo-derived brand colors), apply it to the site's
 * WordPress global styles, and give the narrative (story / mission / values / differentiators /
 * team) the standard-page composer grounds About and Why-Choose-Us on. Same proven
 * {@see ManagesBrandKit} behavior as the guided Brand step it supersedes; voice is NOT here —
 * the new Setup captures it on the Interview/Voice steps. Sits after Connections & Feeds so
 * the apply can push immediately.
 *
 * @property-read bool $pushed
 * @property-read StyleVariation|null $resolvedStyle
 * @property-read StyleVariation|null $chosenStyle
 * @property-read array{primary: string, accent: string}|null $logoColors
 * @property-read bool $usesLogoColors
 */
class BrandStep extends GatheringPage
{
    use ManagesBrandKit, WithFileUploads;

    protected static ?string $slug = 'setup2/brand';

    protected static ?string $navigationLabel = 'Brand';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.gathering.brand-step';

    protected function afterSiteResolved(): void
    {
        $this->reset(['story', 'mission', 'missionEnhance', 'valuesText', 'differentiatorsText', 'team']);

        $site = $this->getSite();
        if ($site !== null) {
            $this->loadBrandState($site);
        }
    }

    /** The new-Setup gate: a WP connection must exist (step 6); the activator reports the rest. */
    protected function brandPushBlocked(): ?string
    {
        $site = $this->getSite();
        if ($site === null) {
            return 'Pick a site first.';
        }

        $connected = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword)
            ->exists();

        return $connected ? null : 'Connect WordPress first (Connections & Feeds).';
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return ['state' => 'empty', 'label' => 'Empty'];
        }

        if ($this->getPushedProperty()) {
            return ['state' => 'complete', 'label' => 'Brand applied'];
        }

        $hasNarrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();

        return $hasNarrative
            ? ['state' => 'attention', 'label' => 'Narrative saved — apply the look']
            : ['state' => 'empty', 'label' => 'Empty — pick a look, tell the story'];
    }
}
