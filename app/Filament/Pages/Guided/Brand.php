<?php

namespace App\Filament\Pages\Guided;

use App\Branding\BrandStudio;
use App\Enums\SetupStep;
use App\Enums\VoiceStatus;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use App\Onboarding\IntakeCollector;
use App\Operator\Controls\VoiceControl;
use Filament\Notifications\Notification;

/**
 * Step 3 · Brand — palette / typography → brand kit → push. Folds the standalone brand step into
 * the flow ({@see BrandStudio}: generate + save + push to the site's Elementor Global Kit). The
 * push is reachable only once step 2 set `deps_ready` (Brand's prerequisite), so the brand kit
 * can't be pushed to an unprepared WordPress — the /brand-kit 404 can't recur. `brand_pushed` is
 * the completion flag.
 *
 * @property-read array{palette: array<string, string>, typography: array<string, string>}|null $branding
 * @property-read bool $pushed
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

    /**
     * @return array{palette: array<string, string>, typography: array<string, string>}|null
     */
    public function getBrandingProperty(): ?array
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($branding === null || ! is_array($branding->palette)) {
            return null;
        }

        return [
            'palette' => $branding->palette,
            'typography' => is_array($branding->typography) ? $branding->typography : [],
        ];
    }

    public function getPushedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->brand_pushed;
    }

    /** Generate a brand (industry-grounded) and save it — the preview before pushing. */
    public function generate(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $studio = app(BrandStudio::class);
        $brand = $studio->generate($site, []); // industry resolved from the site
        $studio->save($site, $brand->palette, $brand->typography);

        Notification::make()->title('Brand generated — review, then push.')->success()->send();
    }

    /** Push the saved brand kit to the prepped WordPress (gated on deps_ready). */
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

        $result = app(BrandStudio::class)->push($site);
        if ($result['updated'] ?? false) {
            $gate->state($site)->update(['brand_pushed' => true]);
            Notification::make()->title('Brand kit pushed to WordPress.')->success()->send();

            return;
        }

        Notification::make()->title('Brand push failed')->body((string) ($result['error'] ?? 'Try again.'))->danger()->send();
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
