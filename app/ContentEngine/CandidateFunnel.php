<?php

namespace App\ContentEngine;

use App\Enums\AlertType;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\NearDupTier;
use App\Enums\RelevanceBand;
use App\Integrations\News\NewsItem;
use App\Integrations\News\NewsProvider;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The §6a candidate funnel: raw intake → pre-filter → same-story clustering →
 * relevance scoring (route + angle + gates) → near-dup → routed, draft-ready
 * candidates with angle hints. Drafting, the review queue, and publish are
 * explicitly out of scope (§6b/c).
 */
class CandidateFunnel
{
    private const BACKFILL_LOOKBACK_DAYS = 365;

    public function __construct(
        private readonly NewsProvider $news,
        private readonly PreFilter $preFilter,
        private readonly SameStoryClusterer $clusterer,
        private readonly RelevanceScorer $scorer,
        private readonly NearDuplicateDetector $nearDup,
        private readonly BackfillSplitter $splitter,
        private readonly ReactiveTopicGate $topicGate,
    ) {}

    /**
     * Steady-state ingestion of a feed.
     *
     * @param  array<string, mixed>  $feedConfig
     */
    public function ingest(Site $site, array $feedConfig): FunnelResult
    {
        return $this->process($site, $this->news->fetch($feedConfig));
    }

    /**
     * First-run backfill: pull ~1yr, split at the freshness cutoff. Older items
     * become the silo-discovery corpus (never drafted); newer items flow through
     * the normal pipeline.
     *
     * @param  array<string, mixed>  $feedConfig
     */
    public function backfill(Site $site, array $feedConfig, int $cutoffDays = 90): BackfillResult
    {
        $since = (new DateTimeImmutable)->modify('-'.self::BACKFILL_LOOKBACK_DAYS.' days');
        $split = $this->splitter->split($this->news->fetch($feedConfig, $since), $cutoffDays);

        return new BackfillResult(
            corpus: $this->discoveryCorpus($split['archive']),
            recent: $this->process($site, $split['recent']),
        );
    }

    /**
     * @param  list<NewsItem>  $items
     * @param  string|null  $routingHint  The originating feed's silo_id, passed to
     *                                    the scorer as a routing backstop (used
     *                                    only when content scoring finds no silo).
     */
    public function process(Site $site, array $items, ?string $routingHint = null): FunnelResult
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $gateOn = (bool) config('launchpad.reactive.enabled', true);
        $footprint = $gateOn ? $this->footprintStates($site) : [];

        // Article-identity dedup: the hourly ingest re-serves the same articles, and Haiku may route the
        // same story to a different silo each run — so the silo-scoped near-dup net can miss it. Skip any
        // item we've already ingested for this site (any status), keyed on the feed's stable externalId.
        $known = $this->knownExternalIds($site, $items);
        $seen = [];

        $dropped = [];
        $filtered = [];
        foreach ($items as $item) {
            $ext = trim($item->externalId);
            if ($ext !== '' && (isset($known[$ext]) || isset($seen[$ext]))) {
                $dropped[] = ['title' => $item->title, 'reason' => 'already_ingested'];

                continue;
            }
            if ($ext !== '') {
                $seen[$ext] = true;
            }

            if (! $this->preFilter->passes($item)) {
                $dropped[] = ['title' => $item->title, 'reason' => 'pre_filter'];

                continue;
            }
            // Reactive topical + geography gate — drop municipal-finance / out-of-footprint noise before
            // any LLM relevance cost (the Aug-4 sewer-grant leak). Install-gated (off in the generic tests).
            if ($gateOn && ($reason = $this->topicGate->rejection($item, $footprint)) !== null) {
                $dropped[] = ['title' => $item->title, 'reason' => $reason];

                continue;
            }
            $filtered[] = $item;
        }

        $created = [];
        $parked = [];
        $refreshMarked = [];
        $alerts = [];

        // Per-silo backpressure: seed each silo's current UNREVIEWED backlog once, then decrement headroom
        // as we route into it this run — so a single run can't blow past the cap either. A silo at the cap
        // ingests nothing more until the operator works the backlog down (per silo; others keep flowing).
        $backpressure = (int) config('launchpad.reactive.candidate_backpressure_per_silo', 10);
        $backlog = $backpressure > 0 ? $this->unreviewedBacklog($site) : [];
        $backpressureLogged = [];

        foreach ($this->clusterer->cluster($filtered) as $cluster) {
            $item = $cluster->representative;
            $relevance = $this->scorer->score($item, $silos, $routingHint);

            if ($relevance->band === RelevanceBand::Dropped) {
                $dropped[] = ['title' => $item->title, 'reason' => $this->dropReason($relevance)];
                if (! $relevance->brandSafe) {
                    $alerts[] = new OperatorAlert(AlertType::BrandSafetyRejected, null, "Rejected (brand safety): {$item->title}");
                }

                continue;
            }

            // Backpressure gate — checked here (after scoring routes the item, the earliest the target silo
            // is known) and BEFORE the near-dup fetch + candidate create, so a saturated silo costs nothing
            // more. Counts unreviewed rows created earlier this run too (the seeded backlog is decremented).
            $siloId = (string) $relevance->matchedSiloId;
            $siloBacklog = array_key_exists($siloId, $backlog) ? $backlog[$siloId] : 0;
            if ($backpressure > 0 && $siloBacklog >= $backpressure) {
                $dropped[] = ['title' => $item->title, 'reason' => 'silo_backpressure'];
                if (! isset($backpressureLogged[$siloId])) {
                    Log::info('candidate.backpressure.skipped', [
                        'site_id' => $site->id, 'silo_id' => $siloId, 'unreviewed' => $siloBacklog, 'cap' => $backpressure,
                    ]);
                    $backpressureLogged[$siloId] = true;
                }

                continue;
            }

            $existing = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('silo_id', $relevance->matchedSiloId)
                ->get();

            $dup = $this->nearDup->detect($item->text(), $existing);

            if ($dup->tier === NearDupTier::Refresh) {
                $refreshMarked[] = new RefreshMark($item->title, $dup->similarToContentId, $dup->signal());
                $alerts[] = new OperatorAlert(
                    AlertType::RefreshSuggested,
                    $dup->similarToContentId,
                    "Refresh the existing page instead of duplicating: {$item->title}",
                    ['similarity' => $dup->signal()],
                );

                continue;
            }

            $status = $relevance->band === RelevanceBand::Borderline ? ContentStatus::InReview : ContentStatus::Candidate;
            $content = $this->createCandidate($site, $item, $relevance, $status);
            // Both candidate + in_review are "unreviewed" — count this new row toward the silo's backlog.
            $backlog[$siloId] = $siloBacklog + 1;

            if ($relevance->band === RelevanceBand::Borderline) {
                $parked[] = $content;
                $alerts[] = new OperatorAlert(AlertType::BorderlineRelevance, $content->id, "Borderline relevance parked: {$item->title}");
            } else {
                $created[] = $content;
            }

            if ($dup->tier === NearDupTier::OperatorFlag) {
                $alerts[] = new OperatorAlert(
                    AlertType::NearDuplicateFlag,
                    $content->id,
                    "Possible duplicate — merge or keep distinct: {$item->title}",
                    ['similar_to' => $dup->similarToContentId, 'similarity' => $dup->signal()],
                );
            }
        }

        return new FunnelResult($created, $parked, $refreshMarked, $alerts, $dropped);
    }

    /**
     * @param  list<NewsItem>  $archive
     * @return list<DiscoveryCluster>
     */
    private function discoveryCorpus(array $archive): array
    {
        $filtered = array_values(array_filter($archive, fn (NewsItem $i) => $this->preFilter->passes($i)));

        $corpus = array_map(function (NewsCluster $cluster) {
            return new DiscoveryCluster(
                theme: $cluster->representative->topic ?? $cluster->representative->title,
                items: $cluster->members,
                rank: (float) $cluster->outletCount(),
            );
        }, $this->clusterer->cluster($filtered));

        usort($corpus, fn (DiscoveryCluster $a, DiscoveryCluster $b) => $b->rank <=> $a->rank);

        return $corpus;
    }

    private function createCandidate(Site $site, NewsItem $item, RelevanceResult $relevance, ContentStatus $status): Content
    {
        return Content::create([
            'site_id' => $site->id,
            'silo_id' => $relevance->matchedSiloId,
            'matched_silo_id' => $relevance->matchedSiloId,
            'source_id' => $item->feedId,
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Reactive,
            'status' => $status,
            'title' => $item->title,
            'slug' => $this->uniqueSlug($site->id, $item->title),
            'source_name' => $item->sourceName,
            'source_url' => $item->url,
            'external_id' => $item->externalId !== '' ? $item->externalId : null,
            'angle_hint' => $relevance->angleHint,
            'relevance_score' => round($relevance->score, 4),
            'local_relevance' => $relevance->localRelevance,
            // The board reads these off `meta` (no migration): the timeliness pill and the article's real
            // publish date (source pubDate — distinct from ingest `created_at` and WP `published_at`).
            'meta' => [
                'classification' => $relevance->classification->value,
                'source_published_at' => $item->publishedAt->format('Y-m-d'),
            ],
            'version' => 1,
        ]);
    }

    /**
     * The externalIds already present as Content for this site, restricted to the incoming batch so the
     * lookup stays a single indexed query regardless of how large the site's history is.
     *
     * @param  list<NewsItem>  $items
     * @return array<string, true>
     */
    private function knownExternalIds(Site $site, array $items): array
    {
        $incoming = array_values(array_unique(array_filter(array_map(
            fn (NewsItem $i): string => trim($i->externalId),
            $items,
        ), fn (string $id): bool => $id !== '')));

        if ($incoming === []) {
            return [];
        }

        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('external_id', $incoming)
            ->pluck('external_id')
            ->all();

        return array_fill_keys(array_map('strval', $existing), true);
    }

    /**
     * The current UNREVIEWED candidate count per silo for this site — status candidate/in_review, the
     * operator's un-triaged backlog (drafted/published rows have moved on and don't count). One grouped
     * query; the funnel decrements headroom in-memory as it routes new candidates this run.
     *
     * @return array<string, int> silo_id => unreviewed count
     */
    private function unreviewedBacklog(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::InReview->value])
            ->whereNotNull('silo_id')
            ->selectRaw('silo_id, count(*) as aggregate')
            ->groupBy('silo_id')
            ->pluck('aggregate', 'silo_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    private function dropReason(RelevanceResult $relevance): string
    {
        return match (true) {
            $relevance->competitorPromo => 'competitor_promo',
            ! $relevance->brandSafe => 'brand_safety',
            $relevance->matchedSiloId === null => 'no_silo_match',
            default => 'below_threshold',
        };
    }

    /**
     * The tenant's own states (2-letter upper) — every physical Location's state + the corporate state.
     * Empty when unknown, in which case the geography gate never guesses a boundary.
     *
     * @return list<string>
     */
    private function footprintStates(Site $site): array
    {
        $states = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get()
            ->map(fn (Location $l): string => strtoupper(trim((string) $l->cityState()['state'])))
            ->all();

        return collect($states)
            ->push(strtoupper(trim((string) $site->corporate_state)))
            ->filter(fn (string $s): bool => $s !== '')
            ->unique()->values()->all();
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 1;

        while (Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
