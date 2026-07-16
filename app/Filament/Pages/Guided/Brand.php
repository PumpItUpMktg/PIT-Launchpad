<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Enums\VoiceStatus;
use App\Filament\Concerns\ManagesBrandKit;
use App\Filament\Pages\Gathering\BrandStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\VoiceProfile;
use App\Onboarding\IntakeCollector;
use App\Operator\Controls\VoiceControl;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;
use Filament\Notifications\Notification;
use Livewire\WithFileUploads;

/**
 * Step 3 · Brand — voice → look → narrative. The Gutenberg-pivot brand step: capture the brand voice,
 * pick a look (one of three theme.json style variations, recommended from the voice), and give the
 * words the standard-page composer grounds on. "Apply" activates the chosen variation on the site's
 * WordPress global styles via {@see StyleActivator} — there is no Elementor Global Kit. The apply is
 * reachable only once step 2 set `deps_ready`; `brand_pushed` is the completion flag.
 *
 * SUPERSEDED by the new Setup's Brand step ({@see BrandStep}),
 * which hosts the same {@see ManagesBrandKit} look + narrative behavior (voice lives on the new
 * Setup's own Interview/Voice steps). Stays routable inside the guided flow.
 *
 * @property-read bool $pushed
 * @property-read StyleVariation|null $resolvedStyle
 * @property-read StyleVariation|null $chosenStyle
 */
class Brand extends GuidedPage
{
    use ManagesBrandKit, WithFileUploads;

    protected static ?string $slug = 'setup/brand';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Brand';

    /** Menu-map family tag: superseded by the new Setup's Brand step. */
    public static function menuTag(): string
    {
        return 'setup';
    }

    protected string $view = 'filament.guided.brand';

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

        $this->loadBrandState($site);

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

    /** The guided gate: the brand push waits for step 2's WP connect + prep (`deps_ready`). */
    protected function brandPushBlocked(): ?string
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->deps_ready
            ? null
            : 'Connect & prep WordPress first.';
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
            'audience' => trim($this->voiceAudience) !== '' ? trim($this->voiceAudience) : 'homeowners',
            'credibility' => trim($this->voiceCredibility) !== '' ? trim($this->voiceCredibility) : 'licensed and insured',
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
        $this->redirect(SetupStep::WhereYouWork->pageClass()::getUrl());
    }
}
