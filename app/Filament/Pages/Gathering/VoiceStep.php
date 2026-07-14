<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\VoiceStatus;
use App\Models\Scopes\SiteScope;
use App\Models\VoiceProfile;
use App\Operator\Controls\VoiceControl;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * New Setup · Step 5 — Voice review surface. The extraction-seeded draft profile (from the
 * interview's voice cues) rendered editable — persona, language rules, audience, reading level,
 * CTA voice — with the "from interview" chip when seeded. Saving confirms; Activate promotes the
 * draft through the standard one-active-per-site path ({@see VoiceControl}).
 *
 * @property-read Collection<int, VoiceProfile> $profiles
 * @property-read VoiceProfile|null $draft
 */
class VoiceStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/voice';

    protected static ?string $navigationLabel = 'Voice';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.gathering.voice-step';

    public string $persona = '';

    public string $languageRules = '';

    public string $audience = '';

    public string $readingLevel = '';

    public string $ctaVoice = '';

    protected function afterSiteResolved(): void
    {
        $draft = $this->getDraftProperty();
        $this->persona = trim((string) (($draft?->persona ?? [])['description'] ?? ''));
        $this->languageRules = collect($draft?->language_rules ?? [])->map(fn ($r) => (string) $r)->implode("\n");
        $this->audience = collect($draft?->audience ?? [])->map(fn ($a) => (string) $a)->implode("\n");
        $this->readingLevel = (string) ($draft?->reading_level ?? '');
        $this->ctaVoice = (string) ($draft?->cta_voice ?? '');
    }

    /** @return Collection<int, VoiceProfile> */
    public function getProfilesProperty(): Collection
    {
        if ($this->siteId === null) {
            return new Collection;
        }

        return VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderByDesc('version')
            ->get();
    }

    public function getDraftProperty(): ?VoiceProfile
    {
        return $this->getProfilesProperty()->first(fn (VoiceProfile $p) => $p->status === VoiceStatus::Draft);
    }

    public function save(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $draft = $this->getDraftProperty();
        if ($draft === null) {
            $max = (int) VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->max('version');
            $draft = new VoiceProfile;
            $draft->forceFill([
                'site_id' => $site->id,
                'version' => $max + 1,
                'status' => VoiceStatus::Draft,
                'framing_model' => 'problem_solution',
            ]);
        }

        $rules = collect(preg_split('/\r?\n/', $this->languageRules) ?: [])->map(fn ($l) => trim((string) $l))->filter()->values()->all();
        $audience = collect(preg_split('/\r?\n/', $this->audience) ?: [])->map(fn ($l) => trim((string) $l))->filter()->values()->all();

        $draft->forceFill([
            'persona' => trim($this->persona) !== '' ? ['description' => trim($this->persona)] : null,
            'language_rules' => $rules !== [] ? $rules : null,
            'audience' => $audience !== [] ? $audience : null,
            'reading_level' => trim($this->readingLevel) !== '' ? trim($this->readingLevel) : null,
            'cta_voice' => trim($this->ctaVoice) !== '' ? trim($this->ctaVoice) : null,
        ])->save();

        $this->confirmSeeded($draft, ['profile']);

        Notification::make()->success()->title("Voice draft v{$draft->version} saved")->send();
    }

    /** Promote the draft through the standard one-active-per-site path. */
    public function activate(): void
    {
        $draft = $this->getDraftProperty();
        if ($draft === null) {
            Notification::make()->warning()->title('No draft to activate — save one first.')->send();

            return;
        }

        app(VoiceControl::class)->activate($draft);
        Notification::make()->success()->title("Voice v{$draft->version} is now active")->send();
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $profiles = $this->getProfilesProperty();
        if ($profiles->contains(fn (VoiceProfile $p) => $p->status === VoiceStatus::Active)) {
            return ['state' => 'complete', 'label' => 'Complete — a profile is active'];
        }

        return $profiles->isEmpty()
            ? ['state' => 'empty', 'label' => 'No voice profile yet']
            : ['state' => 'attention', 'label' => 'Draft only — activate when it reads right'];
    }
}
