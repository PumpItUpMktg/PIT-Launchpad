<?php

namespace App\Build;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Guided\StepGate;
use App\Models\BuildPage;
use App\Models\Site;

/**
 * Drives the build manifest through its lifecycle. **Stub seam:** real drafting/composition (the
 * VoiceKit-injected fold→section pipeline) and the drip controller aren't landed, so a `tick`
 * advances queued pages to their next resting state — auto pages publish, review-gated pages park
 * in `in_review` — without generating content. When the real composition entrypoint lands it
 * slots in here (queued → drafting → composed → published) with no change to the surfaces.
 *
 * The Build phase hands off to Grow once the foundation is live ({@see launchReady()}): all
 * review-required pages and all fixed standard pages published.
 */
class BuildRunner
{
    public function __construct(
        private readonly StepGate $steps,
    ) {}

    /**
     * Advance the manifest one step (priority order). Returns counts of what moved.
     *
     * @return array{published: int, in_review: int}
     */
    public function tick(Site $site): array
    {
        $published = 0;
        $inReview = 0;

        $queued = BuildPage::query()
            ->where('site_id', $site->id)
            ->where('status', BuildStatus::Queued->value)
            ->orderBy('priority')
            ->get();

        foreach ($queued as $page) {
            if ($page->review_required) {
                $page->update(['status' => BuildStatus::InReview]); // brand-critical: awaits review
                $inReview++;
            } else {
                $page->update(['status' => BuildStatus::Published]); // auto-build (stub-published)
                $published++;
            }
        }

        $this->reconcileLaunch($site);

        return ['published' => $published, 'in_review' => $inReview];
    }

    /** Publish a reviewed (brand-critical) page; no-op unless it's awaiting review. */
    public function publishReviewed(Site $site, BuildPage $page): bool
    {
        if ($page->site_id !== $site->id || $page->status !== BuildStatus::InReview) {
            return false;
        }

        $page->update(['status' => BuildStatus::Published]);
        $this->reconcileLaunch($site);

        return true;
    }

    /** The foundation is live: every review-gated page AND every fixed standard page published. */
    public function launchReady(Site $site): bool
    {
        $base = BuildPage::query()->where('site_id', $site->id);
        if ((clone $base)->count() === 0) {
            return false;
        }

        $reviewPending = (clone $base)->where('review_required', true)
            ->where('status', '!=', BuildStatus::Published->value)->exists();

        $standardPending = (clone $base)->where('source', BuildSource::Standard->value)
            ->where('status', '!=', BuildStatus::Published->value)->exists();

        return ! $reviewPending && ! $standardPending;
    }

    /** Flip the site to launched once the foundation is live. */
    private function reconcileLaunch(Site $site): void
    {
        if (! $this->launchReady($site)) {
            return;
        }

        $state = $this->steps->state($site);
        if (! $state->launched) {
            $state->update(['launched' => true, 'build_status' => 'live']);
        }
    }
}
