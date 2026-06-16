<?php

namespace App\Filament\Pages;

use App\Interview\Conversation\Interviewer;
use App\Interview\Conversation\InterviewSession;
use App\Interview\ExtractionResult;
use App\Interview\InterviewExtractor;
use App\Interview\InterviewPersister;
use App\Interview\SeedExtractionException;
use App\Interview\SiloSeed;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The conversational owner-interview surface (operator admin, PR #2 of the
 * silo-generator arc). A multi-turn chat over the proven {@see Interviewer} engine:
 * the operator picks a Site, converses on the owner's behalf, then extracts and — on
 * confirm — persists a SiloBlueprint + an activated VoiceProfile via the same
 * services the CLI uses. The transcript lives in a Livewire property so it survives
 * across requests; the engine rebuilds from it each turn.
 *
 * @property-read array<string, string> $siteOptions
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

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public bool $persisted = false;

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function start(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Pick a site to interview first.')->warning()->send();

            return;
        }

        $session = InterviewSession::start(app(Interviewer::class));

        $this->messages = $session->toArray();
        $this->ready = $session->isReady();
        $this->started = true;
        $this->preview = null;
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
            $this->preview = $session->extract(app(InterviewExtractor::class))->toArray();
        } catch (SeedExtractionException $e) {
            Notification::make()->title('Could not extract a clean seed')->body($e->getMessage())->danger()->send();
        }
    }

    public function persist(): void
    {
        if ($this->preview === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            Notification::make()->title('Site not found.')->danger()->send();

            return;
        }

        /** @var array<string, mixed> $seedData */
        $seedData = $this->preview['seed'];
        /** @var array<string, mixed> $voice */
        $voice = $this->preview['voice'];

        $result = app(InterviewPersister::class)->persist(
            $site,
            new ExtractionResult(SiloSeed::fromArray($seedData), $voice),
        );

        $this->persisted = true;

        Notification::make()
            ->title('Saved')
            ->body("Blueprint stored; voice v{$result->voice->version} is now active.")
            ->success()
            ->send();
    }
}
