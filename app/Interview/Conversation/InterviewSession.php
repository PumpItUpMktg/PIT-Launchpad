<?php

namespace App\Interview\Conversation;

use App\Interview\ExtractionResult;
use App\Interview\InterviewExtractor;

/**
 * Stateful holder for a multi-turn owner interview: the transcript + optional GBP
 * signals + the latest readiness. It is the thin orchestration over {@see Interviewer}
 * (per-turn questioning) and {@see InterviewExtractor} (the proven single-shot
 * seed/voice extraction, run over the assembled transcript once ready). It rebuilds
 * losslessly from an array so a Livewire component or console loop can persist the
 * transcript across requests.
 */
final class InterviewSession
{
    /**
     * @param  list<Turn>  $turns
     * @param  list<string>|null  $gbpSignals
     */
    private function __construct(
        private array $turns,
        private readonly ?array $gbpSignals,
        private bool $ready,
    ) {}

    /**
     * Start a fresh interview, seeding the interviewer's opening question.
     *
     * @param  list<string>|null  $gbpSignals
     */
    public static function start(Interviewer $interviewer, ?array $gbpSignals = null): self
    {
        $reply = $interviewer->next([], $gbpSignals);

        return new self([Turn::assistant($reply->message)], $gbpSignals, $reply->ready);
    }

    /**
     * Rebuild a session from a stored transcript (e.g. a Livewire property).
     *
     * @param  list<array<string, mixed>>  $turns
     * @param  list<string>|null  $gbpSignals
     */
    public static function fromArray(array $turns, ?array $gbpSignals = null, bool $ready = false): self
    {
        return new self(array_map(Turn::fromArray(...), $turns), $gbpSignals, $ready);
    }

    /**
     * Record the owner's answer and get the interviewer's next message.
     */
    public function submit(Interviewer $interviewer, string $ownerMessage): InterviewReply
    {
        $this->turns[] = Turn::owner(trim($ownerMessage));

        $reply = $interviewer->next($this->turns, $this->gbpSignals);

        $this->turns[] = Turn::assistant($reply->message);
        $this->ready = $reply->ready;

        return $reply;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Run the proven extractor over the full transcript → SiloSeed + VoiceProfile.
     */
    public function extract(InterviewExtractor $extractor): ExtractionResult
    {
        return $extractor->extract($this->transcriptText(), $this->gbpSignals);
    }

    /**
     * @return list<Turn>
     */
    public function turns(): array
    {
        return $this->turns;
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (Turn $turn) => $turn->toArray(), $this->turns);
    }

    private function transcriptText(): string
    {
        $lines = [];
        foreach ($this->turns as $turn) {
            $lines[] = ($turn->isOwner() ? 'Owner' : 'Interviewer').': '.$turn->text;
        }

        return implode("\n", $lines);
    }
}
