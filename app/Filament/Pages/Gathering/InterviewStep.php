<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Gathering\IntakeExtractor;
use App\Gathering\InterviewEngine;
use App\Interview\Invites\InterviewInvites;
use App\Mail\InterviewInviteMail;
use App\Models\Interview;
use App\Models\InterviewInvite;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

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

    /** Client link (relay PR 3): who to send it to, and the one-time link shown right after issuing. */
    public string $inviteEmail = '';

    public ?string $issuedLink = null;

    /** The site's live client link, if any. */
    public function getInviteProperty(): ?InterviewInvite
    {
        $site = $this->getSite();

        return $site === null ? null : app(InterviewInvites::class)->live($site);
    }

    /** How many answers the OWNER has typed on the client link (0 = operator-led or not started). */
    public function getClientAnswersProperty(): int
    {
        $interview = $this->getInterviewProperty();

        return $interview === null ? 0 : $interview->turns()->where('role', 'owner')->count();
    }

    /**
     * Issue (or re-issue) the client link — the previous one dies — and send the invite email, now, when
     * an address is given. The plaintext link is shown ONCE on this page (it is stored hashed).
     */
    public function sendLink(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $email = trim($this->inviteEmail);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Notification::make()->warning()->title('That email address doesn\'t look right.')->send();

            return;
        }

        $issued = app(InterviewInvites::class)->issue($site, $email !== '' ? $email : null, $this->operatorId());
        $this->issuedLink = route('interview.show', ['token' => $issued->plaintext]);

        if ($email === '') {
            Notification::make()->success()
                ->title('Link issued')
                ->body('Any earlier link no longer works. The link below is shown once — copy it now if you need it.')
                ->send();

            return;
        }

        // Sent NOW, from this request, not queued. An invite is one call to the mail API — well under a
        // second — and the operator is standing on this page to learn whether it went. Queued, it rode the
        // `default` lane behind twenty-six page generations on one worker while the page said "Link sent";
        // the owner never got it, and nobody could tell. sendNow() bypasses the mailable's ShouldQueue.
        // A failure is reported as a failure, and the link is still issued: copy it and send it by hand.
        try {
            Mail::to($email)->sendNow(new InterviewInviteMail((string) $issued->invite->id, $issued->plaintext));
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()
                ->title("Could not email {$email}")
                ->body('The link is live — copy it below and send it yourself. Mail error: '.$e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        // "Sent" to the log mailer is sent nowhere. Say so, rather than let the operator wait on an owner
        // who was never emailed.
        if ((string) config('mail.default') === 'log') {
            Notification::make()->warning()
                ->title('Mail is set to log — nothing was actually emailed')
                ->body("MAIL_MAILER is 'log' on this environment, so the invite went to the log file, not to {$email}. Copy the link below and send it yourself, or set a real mailer.")
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->success()
            ->title("Link sent to {$email}")
            ->body('Any earlier link no longer works. The link below is shown once — copy it now if you need it.')
            ->send();
    }

    /** Revoke the live client link outright. The interview and its answers stay. */
    public function revokeLink(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $revoked = app(InterviewInvites::class)->revokeLive($site);
        $this->issuedLink = null;

        Notification::make()->success()
            ->title($revoked > 0 ? 'Client link revoked' : 'No live link to revoke')
            ->send();
    }

    private function operatorId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }

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
        $coverage = (array) ($this->getInterviewProperty()->coverage ?? []);

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

        $this->guard(fn () => app(InterviewEngine::class)->start($site));
    }

    public function send(): void
    {
        $interview = $this->openInterview();
        $text = trim($this->input);
        if ($interview === null || $text === '') {
            return;
        }

        // Clear the box up front: answer() persists the owner's turn BEFORE the model call, so even if
        // the question generation fails the answer is saved — a re-send would double-record it. On
        // failure the "Ask again" control regenerates the question from the saved transcript.
        $this->input = '';
        $this->guard(
            fn () => app(InterviewEngine::class)->answer($interview, $text),
            'Your answer was saved. Tap “Ask again” to generate the next question — no need to retype.',
        );
    }

    /** Regenerate the next question after a transient model failure — no new answer is recorded. */
    public function retryQuestion(): void
    {
        $interview = $this->openInterview();
        if ($interview === null) {
            return;
        }

        $this->guard(
            fn () => app(InterviewEngine::class)->resume($interview),
            'Still couldn’t reach the assistant. Please try again in a moment.',
        );
    }

    public function skipSection(string $section): void
    {
        $interview = $this->openInterview();
        $enum = InterviewSection::tryFrom($section);
        if ($interview === null || $enum === null) {
            return;
        }

        $this->guard(fn () => app(InterviewEngine::class)->skipSection($interview, $enum));
    }

    public function addNote(): void
    {
        $interview = $this->openInterview();
        if ($interview === null || trim($this->noteInput) === '') {
            return;
        }

        $note = $this->noteInput;
        $this->noteInput = '';
        $this->guard(fn () => app(InterviewEngine::class)->note($interview, $note));
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
        $answers = $this->getClientAnswersProperty();

        return match ($interview?->status) {
            InterviewStatus::Complete => ['state' => 'complete', 'label' => $answers > 0 ? 'Complete — answered by the client' : 'Complete'],
            InterviewStatus::InProgress => ['state' => 'attention', 'label' => $answers > 0
                ? "In progress — client answering ({$answers} ".($answers === 1 ? 'answer' : 'answers').' so far)'
                : 'In progress — resume anytime'],
            default => ['state' => 'empty', 'label' => $this->getInviteProperty() !== null ? 'Link sent — waiting on the client' : 'Not started (skippable)'],
        };
    }

    /**
     * True when the interview owes the owner a question — the last turn is the operator's (or there
     * are none yet) while still in progress. Normally momentary; it persists only when a model call
     * failed to produce the next question, which is exactly when the "Ask again" control should show.
     */
    public function awaitingQuestion(): bool
    {
        $interview = $this->openInterview();
        if ($interview === null) {
            return false;
        }
        $last = $interview->turns()->reorder()->orderByDesc('id')->first();

        return $last === null || $last->role === 'operator';
    }

    private function openInterview(): ?Interview
    {
        $interview = $this->getInterviewProperty();

        return $interview !== null && $interview->status === InterviewStatus::InProgress ? $interview : null;
    }

    /**
     * Run a model-backed interview action, turning any failure (Claude timeout / 5xx / unparseable
     * reply) into a friendly notice instead of Filament's generic "error loading this page." The
     * transcript is always persisted first, so a failure never loses the owner's answer — the page
     * simply offers a retry.
     */
    private function guard(\Closure $action, string $failBody = 'The assistant hit a snag. Your progress is saved — please try again in a moment.'): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->warning()->title('The assistant didn’t respond')->body($failBody)->send();
        }
    }

    private function runExtraction(Interview $interview): void
    {
        $this->guard(function () use ($interview): void {
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
        }, 'The extraction step couldn’t reach the assistant. Your transcript is saved — tap “Extract now” to retry.');
    }
}
