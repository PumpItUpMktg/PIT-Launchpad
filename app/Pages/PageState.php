<?php

namespace App\Pages;

/**
 * THE CANONICAL STATE VOCABULARY — the law. One source of the words every surface derives from: the
 * operator Grow screen now, the client screen later. Supersedes the first-cut terminology table.
 *
 * Each state carries:
 *  - {@see clientLine()} — the SACRED shared line. Identical on every surface, now and future; never
 *    reworded per-screen. A second wording for the same state is the bug this class exists to prevent.
 *  - {@see whoseMove()} — the small-type whose-move line. Identical across audiences EXCEPT for the
 *    held/failed states, where it flips to operator-truth vs the future client-calm ("nothing needed
 *    — we're handling it"). The operator camp is three-way, mirroring whether there's an on-screen
 *    action: "Your move" only for states with one (generate / review / publish / failed→try again);
 *    "We've got it" while the system is working; and "Not available yet — pending X" for a page
 *    blocked on an UNBUILT feature (the composer, Territory→Market), which is neither the operator's
 *    click nor ours to do — so the copy must not imply a button that isn't there. That split is also
 *    the scannability spine of the screen.
 *  - {@see tone()} — the neutral badge tone (shared presentation hint).
 *
 * The content-specific operator tail (post id, error, queued-at, …) is append-only diagnostic detail
 * built by {@see PageStatePresenter}, never part of the sacred line and never shown to the client.
 *
 * NOTE — `Publishing` extends the relay's eight states: the system has a real approved→live publish
 * transient (statuses Rendering/Publishing) the table didn't tabulate. Modeled here honestly (our
 * move, going live shortly) rather than mislabeled as "Writing now"; its wording is open to revision.
 */
enum PageState: string
{
    case ReadyToGenerate = 'ready_to_generate';
    case Writing = 'writing';
    case ReadyToReview = 'ready_to_review';
    case Approved = 'approved';
    case Publishing = 'publishing';
    case Live = 'live';
    case HeldComposer = 'held_composer';
    case HeldGrounding = 'held_grounding';
    case Failed = 'failed';

    /** The sacred, shared client line — identical on the operator screen and the future client screen. */
    public function clientLine(): string
    {
        return match ($this) {
            self::ReadyToGenerate => 'Ready to generate',
            self::Writing => 'Writing now',
            self::ReadyToReview => 'Ready to review',
            self::Approved => 'Approved — ready to publish',
            self::Publishing => 'Publishing now',
            self::Live => 'Live on your site',
            self::HeldComposer, self::HeldGrounding => "We're still preparing this page",
            self::Failed => "Something went wrong — we're on it",
        };
    }

    /**
     * The whose-move line. Identical for both audiences EXCEPT held/failed, which flip to operator-truth
     * (you unblock it) vs client-calm (nothing needed).
     */
    public function whoseMove(Audience $audience): string
    {
        $operator = $audience === Audience::Operator;

        return match ($this) {
            self::ReadyToGenerate => 'Your move — generate when ready.',
            self::Writing => "We've got it — drafting now, ready shortly.",
            self::ReadyToReview => 'Your move — review the draft and approve.',
            self::Approved => 'Your move — publish when ready.',
            self::Publishing => "We've got it — going live shortly.",
            self::Live => 'Done — nothing needed.',
            // Held states are blocked on UNBUILT features, not an operator click — so the operator
            // camp is "pending-a-build", never "Your move" (which would imply a button that isn't
            // there). "Your move" is reserved for states with an actual on-screen action.
            self::HeldComposer => $operator
                ? 'Not available yet — pending the composer build.'
                : "Nothing needed — we're getting this ready.",
            self::HeldGrounding => $operator
                ? 'Not available yet — pending Territory→Market.'
                : 'Nothing needed — unlocks as coverage grows.',
            // Failed is shown only once retries are exhausted (GeneratePage tries=1; PublishContent
            // tries=3 then terminal) — so it is genuinely a manual re-trigger, never an auto-retry.
            self::Failed => $operator
                ? 'Your move — try again.'
                : "Nothing needed — we're on it.",
        };
    }

    /** The neutral badge tone (ok / warn / info / danger / idle), shared across surfaces. */
    public function tone(): string
    {
        return match ($this) {
            self::Live, self::Approved => 'ok',
            self::ReadyToReview => 'warn',
            self::Writing, self::Publishing => 'info',
            self::Failed => 'danger',
            self::ReadyToGenerate, self::HeldComposer, self::HeldGrounding => 'idle',
        };
    }

    /** Held/blocked states — the only ones whose whose-move line flips by audience. */
    public function isHeld(): bool
    {
        return $this === self::HeldComposer || $this === self::HeldGrounding;
    }
}
