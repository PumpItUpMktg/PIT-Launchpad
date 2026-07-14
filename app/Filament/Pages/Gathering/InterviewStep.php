<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Gathering\IntakeExtractor;
use App\Gathering\InterviewEngine;
use App\Models\Interview;
use Filament\Notifications\Notification;

/**
 * New Setup · Step 2 — the adaptive owner interview. Operator-led chat: the operator conducts the
 * call and types the owner's answers; the engine produces each next question, tagged with the
 * section goal it probes, and the coverage meter shows what's left mid-call. Entirely skippable —
 * steps 3–5 are directly editable; skipping just means no seeding. Resume works by construction
 * (the transcript persists; the engine picks up from it).
 *
 * @property-read Interview|null $interview
 * @property-read list<array{section: InterviewSection, state: string}> $meter
 */
class InterviewStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/interview';

    protected static ?string $navigationLabel = 'Interview';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.gathering.interview-step';

    public string $input = '';

    public string $noteInput = '';

    public function getInterviewProperty(): ?Interview
    {
        return $this->siteId === null ? null : Interview::query()
            ->where('site_id', $this->siteId)
            ->latest('started_at')
            ->first();
    }

    /** @return list<array{section: InterviewSection, state: string}> */
    public function getMeterProperty(): array
    {
        $coverage = (array) ($this->getInterviewProperty()?->coverage ?? []);

        return collect(InterviewSection::cases())
            ->map(fn (InterviewSection $s) => [
                'section' => $s,
                'state' => (string) ($coverage[$s->value] ?? 'empty'),
            ])
            ->all();
    }

    public function begin(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        app(InterviewEngine::class)->start($site);
    }

    public function send(): void
    {
        $interview = $this->openInterview();
        if ($interview === null || trim($this->input) === '') {
            return;
        }

        app(InterviewEngine::class)->answer($interview, $this->input);
        $this->input = '';
    }

    public function skipSection(string $section): void
    {
        $interview = $this->openInterview();
        $enum = InterviewSection::tryFrom($section);
        if ($interview === null || $enum === null) {
            return;
        }

        app(InterviewEngine::class)->skipSection($interview, $enum);
    }

    public function addNote(): void
    {
        $interview = $this->openInterview();
        if ($interview === null || trim($this->noteInput) === '') {
            return;
        }

        app(InterviewEngine::class)->note($interview, $this->noteInput);
        $this->noteInput = '';
    }

    /** End early is a first-class operator control — thin sections allowed; extraction runs. */
    public function endInterview(): void
    {
        $interview = $this->openInterview();
        if ($interview === null) {
            return;
        }

        app(InterviewEngine::class)->end($interview);
        $this->runExtraction($interview);
    }

    /** On-demand re-extract — updates only seeded/empty fields, never confirmed ones. */
    public function extract(): void
    {
        $interview = $this->getInterviewProperty();
        if ($interview === null || $interview->turns()->count() === 0) {
            Notification::make()->warning()->title('Nothing to extract yet.')->send();

            return;
        }

        $this->runExtraction($interview);
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $interview = $this->getInterviewProperty();

        return match ($interview?->status) {
            InterviewStatus::Complete => ['state' => 'complete', 'label' => 'Complete'],
            InterviewStatus::InProgress => ['state' => 'attention', 'label' => 'In progress — resume anytime'],
            default => ['state' => 'empty', 'label' => 'Not started (skippable)'],
        };
    }

    private function openInterview(): ?Interview
    {
        $interview = $this->getInterviewProperty();

        return $interview !== null && $interview->status === InterviewStatus::InProgress ? $interview : null;
    }

    private function runExtraction(Interview $interview): void
    {
        $summary = app(IntakeExtractor::class)->extract($interview);

        Notification::make()->success()
            ->title('Extraction complete')
            ->body(sprintf(
                '%d trust fact(s), %d service(s), %d location(s) seeded · %d suggestion(s) for review · voice draft %s. Confirmed fields were left untouched.',
                $summary['trust'],
                $summary['services'],
                $summary['locations'],
                $summary['suggestions'],
                $summary['voice'] ? 'created' : 'unchanged',
            ))
            ->send();
    }
}
