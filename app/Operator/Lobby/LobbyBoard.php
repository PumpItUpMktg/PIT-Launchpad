<?php

namespace App\Operator\Lobby;

use App\Enums\CitationPresence;
use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\LobbyBadgeTier;
use App\Enums\LobbyCardState;
use App\Enums\ReviewStatus;
use App\Enums\SetupStep;
use App\Enums\SiteStatus;
use App\Models\CitationStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\CoverageScanPlan;
use App\Models\Job;
use App\Models\Market;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles the operator lobby in a SINGLE aggregated pass: one `Site` query plus a fixed set of
 * `GROUP BY site_id` aggregates (constant, independent of tenant count) — never a per-card query or a
 * per-card HTTP call. The four persisted `sites` health counters ride along for free on the Site row;
 * every other badge is a cheap grouped count. Search / filter / sort are applied over the assembled
 * cards; badges are derived per card in memory.
 *
 * A card is navigation only — nothing here mutates. The Filament layer maps each badge's `key` to the
 * filtered surface it links to and each card body to the tenant dashboard, entering + locking the
 * tenant in one action.
 */
class LobbyBoard
{
    /** A feed with no items for at least this many days reads as "returned nothing for N days". */
    private const FEED_STALE_DAYS = 6;

    /**
     * @param  string  $filter  'all' | 'attention' | 'onboarding'
     * @param  string  $sort  'attention' (default, most-urgent first) | 'name'
     * @return Collection<int, LobbyCard>
     */
    public function cards(string $search = '', string $filter = 'all', string $sort = 'attention'): Collection
    {
        $sites = $this->sites($search);
        $ids = $sites->pluck('id')->all();

        $agg = $this->aggregates($ids);
        $stepCount = count(SetupStep::setupSteps());

        $cards = $sites->map(fn (Site $site) => $this->card($site, $agg, $stepCount));

        $cards = match ($filter) {
            'attention' => $cards->filter(fn (LobbyCard $c) => $c->needsAttention()),
            'onboarding' => $cards->filter(fn (LobbyCard $c) => $c->state === LobbyCardState::Onboarding),
            default => $cards,
        };

        $cards = $sort === 'name'
            ? $cards->sortBy(fn (LobbyCard $c) => mb_strtolower($c->brandName()))
            : $cards->sortByDesc(fn (LobbyCard $c) => $c->attentionScore());

        return $cards->values();
    }

    /** @return Collection<int, Site> */
    private function sites(string $search): Collection
    {
        return Site::query()
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where(function (Builder $inner) use ($like): void {
                    $inner->where('brand_name', 'like', $like)->orWhere('domain_url', 'like', $like);
                });
            })
            ->get();
    }

    /**
     * Every per-site aggregate, computed in a fixed number of grouped queries. Keys are site ids.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, int|string>> metric => [site_id => value] (feeds_oldest is a timestamp string)
     */
    private function aggregates(array $ids): array
    {
        $now = Carbon::now();
        $feedCutoff = $now->copy()->subDays(self::FEED_STALE_DAYS);

        // Reviews: needs-market (needs_location) and awaiting-approval (pending) in one grouped query.
        $reviews = Review::withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $ids)
            ->groupBy('site_id')
            ->selectRaw('site_id')
            ->selectRaw('SUM(CASE WHEN needs_location THEN 1 ELSE 0 END) as needs_market')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending', [ReviewStatus::Pending->value])
            ->get();

        // Content: pages awaiting review/approval and blog drafts awaiting review in one grouped query.
        $contents = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $ids)
            ->groupBy('site_id')
            ->selectRaw('site_id')
            ->selectRaw('SUM(CASE WHEN kind = ? AND status IN (?, ?) THEN 1 ELSE 0 END) as pages_review', [
                ContentKind::Page->value, ContentStatus::NeedsReview->value, ContentStatus::InReview->value,
            ])
            ->selectRaw('SUM(CASE WHEN kind = ? AND status = ? THEN 1 ELSE 0 END) as blog_review', [
                ContentKind::Post->value, ContentStatus::NeedsReview->value,
            ])
            ->get();

        // Feeds: count of bad feeds + the oldest "last item" so a silent feed can read as a duration.
        $feeds = Source::withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $ids)
            ->where('enabled', true)
            ->where(function (Builder $q) use ($feedCutoff): void {
                $q->whereNotNull('last_error')->orWhere('last_item_at', '<', $feedCutoff);
            })
            ->groupBy('site_id')
            ->selectRaw('site_id, count(*) as bad, min(last_item_at) as oldest_item')
            ->get();

        return [
            'wp_broken' => $this->countMap(
                Connection::withoutGlobalScope(SiteScope::class)
                    ->whereIn('site_id', $ids)
                    ->where('provider', ConnectionProvider::WpAppPassword->value)
                    ->where('compromised', true)
            ),
            'wrong_nap' => $this->countMap(
                CitationStatus::withoutGlobalScope(SiteScope::class)
                    ->whereIn('site_id', $ids)
                    ->where('presence', CitationPresence::PresentMismatch->value)
                    ->where('covered_by_sibling', false)
            ),
            'held_market' => $this->countMap(
                Market::withoutGlobalScope(SiteScope::class)
                    ->whereIn('site_id', $ids)
                    ->where('on_hold', true)
                    ->where('release_at', '<', $now)
            ),
            'jobs_review' => $this->countMap(
                Job::withoutGlobalScope(SiteScope::class)
                    ->whereIn('site_id', $ids)
                    ->where('status', JobStatus::Review->value)
            ),
            'coverage_overdue' => $this->countMap(
                CoverageScanPlan::withoutGlobalScope(SiteScope::class)
                    ->whereIn('site_id', $ids)
                    ->where('enabled', true)
                    ->where('next_run_at', '<=', $now)
            ),
            'reviews_no_market' => $reviews->pluck('needs_market', 'site_id')->map(fn ($v) => (int) $v)->all(),
            'reviews_pending' => $reviews->pluck('pending', 'site_id')->map(fn ($v) => (int) $v)->all(),
            'pages_review' => $contents->pluck('pages_review', 'site_id')->map(fn ($v) => (int) $v)->all(),
            'blog_review' => $contents->pluck('blog_review', 'site_id')->map(fn ($v) => (int) $v)->all(),
            'feeds_bad' => $feeds->pluck('bad', 'site_id')->map(fn ($v) => (int) $v)->all(),
            'feeds_oldest' => $feeds->pluck('oldest_item', 'site_id')->map(fn ($v) => (string) $v)->all(),
            'setup_step' => SetupState::withoutGlobalScope(SiteScope::class)
                ->whereIn('site_id', $ids)->pluck('current_step', 'site_id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /**
     * A grouped `count(*)` keyed by site_id.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function countMap(Builder $query): array
    {
        return $query->groupBy('site_id')
            ->selectRaw('site_id, count(*) as aggregate')
            ->pluck('aggregate', 'site_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<string, array<string, int|string>>  $agg
     */
    private function card(Site $site, array $agg, int $stepCount): LobbyCard
    {
        $id = (string) $site->id;
        $at = fn (string $metric): int => (int) ($agg[$metric][$id] ?? 0);

        // An onboarding tenant is a setup task, not an operational one — no badges, just progress.
        if ($site->status === SiteStatus::Onboarding) {
            return new LobbyCard(
                site: $site,
                state: LobbyCardState::Onboarding,
                badges: [],
                onboardingStep: (int) ($agg['setup_step'][$id] ?? 1),
                onboardingStepCount: $stepCount,
            );
        }

        $badges = $this->badgesFor($site, $agg, $id, $at);

        $hasBlocking = false;
        foreach ($badges as $badge) {
            if ($badge->tier->blocksPublishing()) {
                $hasBlocking = true;
                break;
            }
        }

        $state = match (true) {
            $hasBlocking => LobbyCardState::Blocked,
            $badges !== [] => LobbyCardState::ActivePending,
            default => LobbyCardState::ActiveClean,
        };

        return new LobbyCard($site, $state, $badges);
    }

    /**
     * The tenant's badges, tier-ordered (most-urgent first). Only conditions a person can clear are
     * badged — indexing (Google decides) is a dashboard metric, and raw blog candidates are never
     * badged (Review-stage only).
     *
     * @param  array<string, array<string, int|string>>  $agg
     * @param  callable(string): int  $at
     * @return list<LobbyBadge>
     */
    private function badgesFor(Site $site, array $agg, string $id, callable $at): array
    {
        $t1 = LobbyBadgeTier::BrokenBlocking;
        $t2 = LobbyBadgeTier::WrongData;
        $t3 = LobbyBadgeTier::WorkWaiting;
        $t4 = LobbyBadgeTier::Degrading;

        /** @var list<LobbyBadge> $badges */
        $badges = [];

        // Tier 1 — broken, publishing blocked.
        if ($at('wp_broken') > 0) {
            $badges[] = new LobbyBadge('wp_connection', $t1, 'WordPress connection broken'); // state, no count
        }
        if ((int) $site->publish_failed_count > 0) {
            $badges[] = new LobbyBadge('publish_failed', $t1, 'Publish failures', (int) $site->publish_failed_count);
        }
        if ((int) $site->render_failed_count > 0) {
            $badges[] = new LobbyBadge('render_failed', $t1, 'Stuck renders', (int) $site->render_failed_count);
        }

        // Tier 2 — wrong data reaching the public.
        if ($at('wrong_nap') > 0) {
            $badges[] = new LobbyBadge('wrong_nap', $t2, 'Citations with wrong NAP', $at('wrong_nap'));
        }
        if ($at('held_market') > 0) {
            $badges[] = new LobbyBadge('held_market', $t2, 'Held market past release', $at('held_market'));
        }
        if ($at('reviews_no_market') > 0) {
            $badges[] = new LobbyBadge('reviews_no_market', $t2, 'Reviews with no market', $at('reviews_no_market'));
        }

        // Tier 3 — work waiting on a person.
        if ($at('reviews_pending') > 0) {
            $badges[] = new LobbyBadge('reviews_pending', $t3, 'Reviews awaiting approval', $at('reviews_pending'));
        }
        if ($at('jobs_review') > 0) {
            $badges[] = new LobbyBadge('jobs_review', $t3, 'Jobs awaiting review', $at('jobs_review'));
        }
        if ($at('pages_review') > 0) {
            $badges[] = new LobbyBadge('pages_review', $t3, 'Pages awaiting review', $at('pages_review'));
        }
        if ($at('blog_review') > 0) {
            $badges[] = new LobbyBadge('blog_review', $t3, 'Blog drafts awaiting review', $at('blog_review'));
        }

        // Tier 4 — degrading quietly.
        if ($at('feeds_bad') > 0) {
            $badges[] = new LobbyBadge('feeds_bad', $t4, 'Feeds erroring or empty', $at('feeds_bad'), $this->feedDetail($agg, $id));
        }
        if ($at('coverage_overdue') > 0) {
            $badges[] = new LobbyBadge('coverage_overdue', $t4, 'Overdue coverage scans', $at('coverage_overdue'));
        }

        usort($badges, fn (LobbyBadge $a, LobbyBadge $b) => $a->tier->rank() <=> $b->tier->rank());

        return $badges;
    }

    /**
     * @param  array<string, array<string, int|string>>  $agg
     */
    private function feedDetail(array $agg, string $id): ?string
    {
        $oldest = $agg['feeds_oldest'][$id] ?? '';
        if (! is_string($oldest) || $oldest === '') {
            return null;
        }

        $days = (int) Carbon::parse($oldest)->diffInDays(Carbon::now());

        return $days > 0 ? "no items for {$days} days" : null;
    }
}
