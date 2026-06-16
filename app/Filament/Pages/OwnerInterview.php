<?php

namespace App\Filament\Pages;

use App\Enums\VoiceStatus;
use App\Interview\Conversation\Interviewer;
use App\Interview\Conversation\InterviewSession;
use App\Interview\ExtractionResult;
use App\Interview\InterviewExtractor;
use App\Interview\InterviewPersister;
use App\Interview\SeedExtractionException;
use App\Interview\SiloSeed;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The conversational owner-interview surface (operator admin, PR #2 of the
 * silo-generator arc). A multi-turn chat over the proven {@see Interviewer} engine:
 * the operator picks a Site, converses on the owner's behalf, then extracts to a
 * light, EDITABLE confirm (a sanity check of the seed — not the Phase-4 prune) and
 * persists a SiloBlueprint (seed + raw transcript) plus an activated VoiceProfile.
 * Re-opening a site loads its saved transcript + seed + voice. The transcript lives
 * in a Livewire property so it survives across requests; the engine rebuilds from it.
 *
 * @property-read array<string, string> $siteOptions
 * @property-read bool $hasSaved
 */
class OwnerInterview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Owner Interview';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.owner-interview';

    public ?string $siteId = null;

    /** @var list<array{role: string, text: string}> */
    public array $messages = [];

    public string $draft = '';

    public bool $started = false;

    public bool $ready = false;

    public bool $extracted = false;

    // Editable seed confirm (one item per line for the list fields).
    public string $editTrade = '';

    public string $editAnchors = '';

    public string $editMarkets = '';

    public string $editExclusions = '';

    /** @var array<string, mixed> */
    public array $voice = [];

    public bool $persisted = false;

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function getHasSavedProperty(): bool
    {
        return $this->siteId !== null && SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereNotNull('seed')
            ->exists();
    }

    public function start(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Pick a site to interview first.')->warning()->send();

            return;
        }

        $this->reset(['messages', 'draft', 'ready', 'extracted', 'editTrade', 'editAnchors', 'editMarkets', 'editExclusions', 'voice', 'persisted']);

        $session = InterviewSession::start(app(Interviewer::class));
        $this->messages = $session->toArray();
        $this->ready = $session->isReady();
        $this->started = true;
    }

    /**
     * Re-open the saved interview for the selected site: transcript + seed + voice.
     */
    public function resume(): void
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->first();

        if ($blueprint === null) {
            Notification::make()->title('No saved interview for this site yet.')->warning()->send();

            return;
        }

        $this->messages = $blueprint->transcript ?? [];
        $this->loadSeedFields($blueprint->seed ?? []);
        $this->loadVoice();
        $this->started = true;
        $this->ready = true;
        $this->extracted = true;
        $this->persisted = false;
    }

    public function send(): void
    {
        $text = trim($this->draft);
        if ($text === '' || ! $this->started || $this->ready) {
            return;
        }

        $session = InterviewSession::fromArray($this->messages, null, $this->ready);
        $session->submit(app(Interviewer::class), $text);

        $this->messages = $session->toArray();
        $this->ready = $session->isReady();
        $this->draft = '';
    }

    public function extract(): void
    {
        $session = InterviewSession::fromArray($this->messages, null, $this->ready);

        try {
            $result = $session->extract(app(InterviewExtractor::class));
        } catch (SeedExtractionException $e) {
            Notification::make()->title('Could not extract a clean seed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->loadSeedFields($result->seed->toArray());
        $this->voice = $result->voice;
        $this->extracted = true;
    }

    public function persist(): void
    {
        $site = $this->siteId === null ? null : Site::query()->find($this->siteId);
        if ($site === null) {
            Notification::make()->title('Site not found.')->danger()->send();

            return;
        }

        if (trim($this->editTrade) === '') {
            Notification::make()->title('Trade is required before saving.')->warning()->send();

            return;
        }

        // Edited values are authoritative; genuinely-empty list fields persist empty (never fabricated).
        $seed = new SiloSeed(
            trim($this->editTrade),
            $this->parseList($this->editAnchors),
            $this->parseList($this->editMarkets),
            $this->parseList($this->editExclusions),
        );

        $result = app(InterviewPersister::class)->persist(
            $site,
            new ExtractionResult($seed, $this->voice),
            $this->messages,
        );

        $this->persisted = true;

        Notification::make()
            ->title('Saved')
            ->body("Blueprint + transcript stored; voice v{$result->voice->version} is now active.")
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function loadSeedFields(array $seed): void
    {
        $this->editTrade = (string) ($seed['trade'] ?? '');
        $this->editAnchors = $this->toLines($seed['anchor_services'] ?? []);
        $this->editMarkets = $this->toLines($seed['markets'] ?? []);
        $this->editExclusions = $this->toLines($seed['exclusions'] ?? []);
    }

    private function loadVoice(): void
    {
        $profile = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        $this->voice = $profile === null ? [] : [
            'framing_model' => $profile->framing_model,
            'tone_axes' => $profile->tone_axes,
            'reading_level' => $profile->reading_level,
            'persona' => $profile->persona,
            'language_rules' => $profile->language_rules,
            'audience' => $profile->audience,
            'cta_voice' => $profile->cta_voice,
        ];
    }

    private function toLines(mixed $items): string
    {
        return is_array($items) ? implode("\n", array_map('strval', $items)) : '';
    }

    /**
     * @return list<string>
     */
    private function parseList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw) ?: []), fn ($v) => $v !== ''));
    }
}
