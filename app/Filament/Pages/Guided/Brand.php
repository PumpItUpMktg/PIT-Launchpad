<?php

namespace App\Filament\Pages\Guided;

use App\Branding\BrandVariationBuilder;
use App\Enums\SetupStep;
use App\Enums\VoiceStatus;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use App\Onboarding\IntakeCollector;
use App\Operator\Controls\VoiceControl;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;
use Filament\Notifications\Notification;

/**
 * Step 3 · Brand — voice → look → narrative. The Gutenberg-pivot brand step: capture the brand voice,
 * pick a look (one of three theme.json style variations, recommended from the voice), and give the
 * words the standard-page composer grounds on. "Apply" activates the chosen variation on the site's
 * WordPress global styles via {@see StyleActivator} — there is no Elementor Global Kit. The apply is
 * reachable only once step 2 set `deps_ready`; `brand_pushed` is the completion flag.
 *
 * @property-read bool $pushed
 * @property-read StyleVariation|null $resolvedStyle
 * @property-read StyleVariation|null $chosenStyle
 */
class Brand extends GuidedPage
{
    protected static ?string $slug = 'setup/brand';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Brand';

    protected string $view = 'filament.guided.brand';

    // Brand-narrative intake (the words the standard-page composer grounds About / Why-Choose-Us on).
    public string $story = '';

    public string $mission = '';

    /** One value per line. */
    public string $valuesText = '';

    /** One differentiator per line. */
    public string $differentiatorsText = '';

    // VoiceKit voice setup (the tone the composer writes in; absent → a default voice).
    public string $voiceTone = 'professional_warm';

    public string $voiceAudience = '';

    public string $voiceCredibility = '';

    public bool $voiceSet = false;

    public function step(): SetupStep
    {
        return SetupStep::Brand;
    }

    public function mount(): void
    {
        parent::mount();

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($narrative !== null) {
            $this->story = (string) ($narrative->story ?? '');
            $this->mission = (string) ($narrative->mission ?? '');
            $this->valuesText = $this->toLines($narrative->values);
            $this->differentiatorsText = $this->toLines($narrative->differentiators);
        }

        $voice = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)
            ->first();
        if ($voice !== null) {
            $this->voiceSet = true;
            $this->voiceAudience = (string) data_get($voice->audience, 'primary', '');
            $this->voiceCredibility = (string) data_get($voice->persona, 'credibility', '');
        }
    }

    public function getPushedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->brand_pushed;
    }

    /** The style variation this site will render in (operator override → voice recommendation → default). */
    public function getResolvedStyleProperty(): ?StyleVariation
    {
        $site = $this->getSite();

        return $site !== null ? app(StyleActivator::class)->resolve($site) : null;
    }

    /** The operator's explicit override, or null when following the recommendation. */
    public function getChosenStyleProperty(): ?StyleVariation
    {
        return $this->getSite()?->style_variation;
    }

    /**
     * The logo-derived palette for the "Your brand colors" option — {primary, accent} for the swatch,
     * or null when no usable logo palette exists (the option is then data-gated OUT of the picker).
     *
     * @return array{primary: string, accent: string}|null
     */
    public function getLogoColorsProperty(): ?array
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        $colors = app(StyleActivator::class)->logoColors($site);
        if ($colors === null) {
            return null;
        }

        // Resolve through the builder so the swatch shows the ACTUAL accent (borrowed for a monochrome logo).
        $resolved = app(BrandVariationBuilder::class)->resolve($colors);

        return ['primary' => $resolved['primary'], 'accent' => $resolved['accent']];
    }

    /** Whether "Your brand colors" is the current choice. */
    public function getUsesLogoColorsProperty(): bool
    {
        return (bool) $this->getSite()?->use_logo_colors;
    }

    /**
     * Operator override of the recommended style. `auto` clears the override (follow the voice
     * recommendation). The Gutenberg pivot's recommend-with-override: the system suggests, the human
     * confirms/overrides — brand styling is one of the three theme.json variations.
     */
    public function chooseStyle(string $variation): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        // "Your brand colors" — the logo-derived variation. Kept out of StyleVariation (a separate flag)
        // so the recommendation stays voice-driven; this is an override.
        if ($variation === 'brand_colors') {
            $site->forceFill(['use_logo_colors' => true])->save();
            Notification::make()->title('Style set to your brand colors.')->success()->send();

            return;
        }

        $picked = $variation === 'auto' ? null : StyleVariation::tryFrom($variation);
        $site->forceFill(['style_variation' => $picked, 'use_logo_colors' => false])->save();

        Notification::make()
            ->title($picked !== null ? "Style set to {$picked->label()}." : 'Using the recommended style.')
            ->success()->send();
    }

    /**
     * Apply the resolved style variation to the prepped WordPress (gated on deps_ready) — the pivot's
     * brand push. Activates a theme.json style variation (bold/clean/warm) as the site's global
     * styles; there is no Elementor Global Kit. `brand_pushed` is the completion flag.
     */
    public function pushBrand(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        if (! $gate->state($site)->deps_ready) {
            Notification::make()->title('Connect & prep WordPress first.')->warning()->send();

            return;
        }

        $result = app(StyleActivator::class)->activate($site);
        $variationValue = (string) ($result['variation'] ?? '');
        $label = $variationValue === BrandVariationBuilder::SLUG
            ? BrandVariationBuilder::TITLE
            : (StyleVariation::tryFrom($variationValue)?->label() ?? 'your style');

        if ($result['updated'] ?? false) {
            $gate->state($site)->update(['brand_pushed' => true]);
            Notification::make()->title("Applied {$label} to your site.")->success()->send();

            return;
        }

        Notification::make()->title('Could not apply your style')->body((string) ($result['error'] ?? 'Try again.'))->danger()->send();
    }

    /**
     * Save the brand-narrative intake the standard-page composer grounds on. Optional by design —
     * a page whose required intake is absent holds "needs intake" rather than fabricating; this is
     * the onboarding step that supplies it so About / Why Choose Us can generate.
     */
    public function saveNarrative(): void
    {
        if ($this->getSite() === null) {
            return;
        }

        $this->persistNarrative();
        Notification::make()->title('Brand details saved.')->success()->send();
    }

    /**
     * Set the brand VOICE — synthesise a VoiceProfile from a short interview and activate it (one
     * active per site; activating archives the prior). The composer writes every page in this voice;
     * without it the drafter falls back to a plain default voice, so this is optional but makes the
     * copy sound like the brand. Each save is a new versioned profile (a re-interview).
     */
    public function saveVoice(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $interview = $this->toneAxes($this->voiceTone) + [
            'audience' => $this->clean($this->voiceAudience) ?? 'homeowners',
            'credibility' => $this->clean($this->voiceCredibility) ?? 'licensed and insured',
        ];

        $profile = app(IntakeCollector::class)->synthesizeVoice($site, $interview);
        app(VoiceControl::class)->activate($profile);

        $this->voiceSet = true;
        Notification::make()->title('Brand voice set — your pages will be written in it.')->success()->send();
    }

    /** @return array{formality: float, warmth: float} */
    private function toneAxes(string $tone): array
    {
        return match ($tone) {
            'friendly_warm' => ['formality' => 0.3, 'warmth' => 0.85],
            'direct_expert' => ['formality' => 0.6, 'warmth' => 0.5],
            default => ['formality' => 0.55, 'warmth' => 0.7], // professional_warm
        };
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (! $this->pushed) {
            Notification::make()->title('Push your brand kit first.')->warning()->send();

            return;
        }

        // Persist whatever narrative was entered (idempotent) so it isn't lost on continue.
        $this->persistNarrative();

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Brand);
        $this->redirect(SetupStep::Territory->pageClass()::getUrl());
    }

    private function persistNarrative(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        SiteNarrative::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $site->id],
            [
                'story' => $this->clean($this->story),
                'mission' => $this->clean($this->mission),
                'values' => $this->fromLines($this->valuesText),
                'differentiators' => $this->fromLines($this->differentiatorsText),
            ],
        );
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Split a textarea into a trimmed, non-empty list — null when empty (degrade by omission).
     *
     * @return list<string>|null
     */
    private function fromLines(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        return $lines === [] ? null : $lines;
    }

    /** Render a stored list (strings or {title, description}) back into one-per-line textarea text. */
    private function toLines(mixed $items): string
    {
        if (! is_array($items)) {
            return '';
        }

        $lines = array_map(function (mixed $item): string {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item)) {
                $title = trim((string) ($item['title'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));

                return $description !== '' ? "{$title} — {$description}" : $title;
            }

            return '';
        }, $items);

        return implode("\n", array_filter($lines, fn (string $l): bool => $l !== ''));
    }
}
