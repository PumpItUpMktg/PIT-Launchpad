<?php

namespace App\Operator;

use App\Enums\SiteStatus;
use App\Models\Site;

/**
 * The at-a-glance health of one tenant in the operator's portfolio triage: the
 * review backlog, in-flight work, recent publishing, job failures, and §9
 * credential hygiene — enough to route the operator to "who needs attention now".
 */
final class SiteHealth
{
    public function __construct(
        public readonly Site $site,
        public readonly int $reviewBacklog,
        public readonly int $flaggedCount,
        public readonly int $draftsInFlight,
        public readonly int $publishedThisWeek,
        public readonly int $renderFailed,
        public readonly int $publishFailed,
        public readonly int $staleCredentials,
        public readonly int $compromisedCredentials,
    ) {}

    public function status(): SiteStatus
    {
        return $this->site->status;
    }

    /**
     * Whether this tenant should bubble up the triage list: a failing job, a
     * compromised/stale credential, or a non-trivial review backlog.
     */
    public function needsAttention(): bool
    {
        return $this->renderFailed > 0
            || $this->publishFailed > 0
            || $this->compromisedCredentials > 0
            || $this->staleCredentials > 0
            || $this->reviewBacklog > 0;
    }

    /**
     * A sortable urgency score (higher = more urgent) for the triage default sort.
     */
    public function attentionScore(): int
    {
        return $this->renderFailed * 100
            + $this->publishFailed * 100
            + $this->compromisedCredentials * 50
            + $this->staleCredentials * 20
            + $this->flaggedCount * 10
            + $this->reviewBacklog;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->site->id,
            'brand_name' => $this->site->brand_name,
            'status' => $this->status()->value,
            'review_backlog' => $this->reviewBacklog,
            'flagged' => $this->flaggedCount,
            'drafts_in_flight' => $this->draftsInFlight,
            'published_this_week' => $this->publishedThisWeek,
            'render_failed' => $this->renderFailed,
            'publish_failed' => $this->publishFailed,
            'stale_credentials' => $this->staleCredentials,
            'compromised_credentials' => $this->compromisedCredentials,
            'attention_score' => $this->attentionScore(),
        ];
    }
}
