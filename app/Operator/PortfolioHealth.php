<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Security\ConnectionStaleness;
use App\Security\StaleConnection;
use Illuminate\Support\Collection;

/**
 * The operator portfolio-triage roll-up: per-tenant health across every Site the
 * operator manages — review backlog, in-flight drafts, recent publishing, job
 * failures, and §9 credential hygiene — so the operator can route to whoever
 * needs attention now. The §6c queue is per-tenant; this routes you *to* a tenant.
 */
class PortfolioHealth
{
    /** Pre-publish statuses that count as "drafts in flight". */
    private const IN_FLIGHT = [
        ContentStatus::Candidate,
        ContentStatus::Scored,
        ContentStatus::Drafted,
        ContentStatus::NeedsReview,
        ContentStatus::InReview,
        ContentStatus::Approved,
        ContentStatus::Rendering,
        ContentStatus::Publishing,
    ];

    public function __construct(
        private readonly ConnectionStaleness $staleness,
    ) {}

    /**
     * Health for every tenant, most-urgent first.
     *
     * @return Collection<int, SiteHealth>
     */
    public function all(): Collection
    {
        $staleBySite = $this->staleness->report()
            ->groupBy(fn (StaleConnection $s) => $s->connection->site_id)
            ->map(fn (Collection $rows) => $rows->count());

        return Site::query()
            ->get()
            ->map(fn (Site $site) => $this->forSite($site, (int) ($staleBySite[$site->id] ?? 0)))
            ->sortByDesc(fn (SiteHealth $h) => $h->attentionScore())
            ->values();
    }

    public function forSite(Site $site, ?int $staleCredentials = null): SiteHealth
    {
        $statusCounts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (ContentStatus $s): int => (int) ($statusCounts[$s->value] ?? 0);

        $renderFailed = $count(ContentStatus::RenderFailed);
        $publishFailed = $count(ContentStatus::PublishFailed);
        $inReview = $count(ContentStatus::InReview);

        $draftsInFlight = 0;
        foreach (self::IN_FLIGHT as $status) {
            $draftsInFlight += $count($status);
        }

        return new SiteHealth(
            site: $site,
            reviewBacklog: $count(ContentStatus::NeedsReview),
            flaggedCount: $renderFailed + $publishFailed + $inReview,
            draftsInFlight: $draftsInFlight,
            publishedThisWeek: $this->publishedThisWeek($site),
            renderFailed: $renderFailed,
            publishFailed: $publishFailed,
            staleCredentials: $staleCredentials ?? $this->staleCredentials($site),
            compromisedCredentials: $this->compromisedCredentials($site),
        );
    }

    private function publishedThisWeek(Site $site): int
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->where('published_at', '>=', now()->startOfWeek())
            ->count();
    }

    private function compromisedCredentials(Site $site): int
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('compromised', true)
            ->count();
    }

    private function staleCredentials(Site $site): int
    {
        return $this->staleness->report()
            ->filter(fn (StaleConnection $s) => $s->connection->site_id === $site->id)
            ->count();
    }
}
