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
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use DateTimeImmutable;
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
     */
    public function process(Site $site, array $items): FunnelResult
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        $dropped = [];
        $filtered = [];
        foreach ($items as $item) {
            if ($this->preFilter->passes($item)) {
                $filtered[] = $item;
            } else {
                $dropped[] = ['title' => $item->title, 'reason' => 'pre_filter'];
            }
        }

        $created = [];
        $parked = [];
        $refreshMarked = [];
        $alerts = [];

        foreach ($this->clusterer->cluster($filtered) as $cluster) {
            $item = $cluster->representative;
            $relevance = $this->scorer->score($item, $silos);

            if ($relevance->band === RelevanceBand::Dropped) {
                $dropped[] = ['title' => $item->title, 'reason' => $this->dropReason($relevance)];
                if (! $relevance->brandSafe) {
                    $alerts[] = new OperatorAlert(AlertType::BrandSafetyRejected, null, "Rejected (brand safety): {$item->title}");
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
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Reactive,
            'status' => $status,
            'title' => $item->title,
            'slug' => $this->uniqueSlug($site->id, $item->title),
            'source_name' => $item->sourceName,
            'source_url' => $item->url,
            'angle_hint' => $relevance->angleHint,
            'relevance_score' => round($relevance->score, 4),
            'local_relevance' => $relevance->localRelevance,
            'version' => 1,
        ]);
    }

    private function dropReason(RelevanceResult $relevance): string
    {
        return match (true) {
            ! $relevance->brandSafe => 'brand_safety',
            $relevance->matchedSiloId === null => 'no_silo_match',
            default => 'below_threshold',
        };
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
