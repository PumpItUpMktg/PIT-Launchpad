<?php

namespace App\Operate;

use App\ContentEngine\Feeds\FeedHealth;
use App\Enums\BlogTargetStatus;
use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\FeedStatus;
use App\Models\BlogTarget;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\Source;
use App\Models\VoiceProfile;

/**
 * The Operate dashboard read model (operate relay): ATTENTION ITEMS ONLY, cross-tenant. Every
 * tile is work; every count click-throughs to the filtered surface; a tenant with zero attention
 * items does not appear. No vanity stats, no charts.
 */
class AttentionBoard
{
    public function __construct(private readonly FeedHealth $feedHealth) {}

    /**
     * Per-tenant attention rows (tenants with nothing to do are absent) + the cross-tenant totals.
     *
     * @return array{totals: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $rows = [];
        $totals = ['review' => 0, 'candidates' => 0, 'failures' => 0, 'starved_queues' => 0, 'stale_feeds' => 0, 'setup_gaps' => 0];

        foreach (Site::query()->orderBy('brand_name')->get() as $site) {
            $items = $this->itemsFor($site);
            $sum = array_sum(array_column($items, 'count'));
            if ($sum === 0) {
                continue; // clean tenant — invisible by design
            }

            foreach ($items as $item) {
                $totals[$item['key']] += $item['count'];
            }

            $rows[] = [
                'site_id' => (string) $site->id,
                'tenant' => (string) $site->brand_name,
                'total' => $sum,
                'items' => array_values(array_filter($items, fn (array $i) => $i['count'] > 0)),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return ['totals' => $totals, 'rows' => $rows];
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function itemsFor(Site $site): array
    {
        $posts = fn () => Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);

        $review = $posts()->whereIn('status', [ContentStatus::NeedsReview->value, ContentStatus::InReview->value])->count();
        $candidates = $posts()->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])->count();
        $failures = $posts()->whereIn('status', [ContentStatus::RenderFailed->value, ContentStatus::PublishFailed->value])->count();

        return [
            ['key' => 'review', 'label' => 'awaiting review', 'count' => $review],
            ['key' => 'candidates', 'label' => 'candidates to triage', 'count' => $candidates],
            ['key' => 'failures', 'label' => 'failed pushes', 'count' => $failures],
            ['key' => 'starved_queues', 'label' => 'starved blog queues', 'count' => $this->starvedQueues($site)],
            ['key' => 'stale_feeds', 'label' => 'stale feeds', 'count' => $this->staleFeeds($site)],
            ['key' => 'setup_gaps', 'label' => 'setup gaps', 'count' => $this->setupGaps($site)],
        ];
    }

    /** Silos whose unconsumed blog-target queue is empty or near-empty (the directed lane starves). */
    private function starvedQueues(Site $site): int
    {
        $queuedBySilo = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', BlogTargetStatus::Queued->value)
            ->selectRaw('silo_id, count(*) as n')
            ->groupBy('silo_id')
            ->pluck('n', 'silo_id');

        // Only silos that participate in the blog lane count — ones that have ever held a target.
        $participating = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->distinct()
            ->pluck('silo_id');

        return $participating
            ->filter(fn ($siloId) => (int) ($queuedBySilo[$siloId] ?? 0) <= BlogBoard::NEAR_EMPTY)
            ->count();
    }

    private function staleFeeds(Site $site): int
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Source $s) => $this->feedHealth->status($s) === FeedStatus::Unhealthy)
            ->count();
    }

    /** The gathering readiness gaps, cheap: no services / no served towns / no active voice / no WP. */
    private function setupGaps(Site $site): int
    {
        $gaps = 0;

        if (! Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists()) {
            $gaps++;
        }

        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        if ($locations->isNotEmpty() && $locations->every(fn (Location $l) => collect($l->served_towns ?? [])->isEmpty())) {
            $gaps++;
        }

        if (! VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('status', 'active')->exists()) {
            $gaps++;
        }

        if (! Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)->exists()) {
            $gaps++;
        }

        return $gaps;
    }
}
