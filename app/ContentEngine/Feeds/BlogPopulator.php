<?php

namespace App\ContentEngine\Feeds;

use App\KeywordGenerator\KeywordRebucketer;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Source;

/**
 * Runs the "make the blog populate" chain end-to-end for one site — the button/command the operator
 * reaches for when a freshly-set-up site shows an empty blog. It's the same steps the daily/hourly
 * schedule runs, gathered into one on-demand pass, and every stage is counted so the result explains
 * itself (see {@see BlogPopulationReport}).
 *
 *   1. re-file any unassigned keywords into silos by rule_set match (cheap, DB)
 *   2. reconcile generated Google-News feeds from the silo'd keyword map × markets (cheap, DB)
 *   3. ingest every active feed → the §6a candidate funnel (HTTP + scoring — the expensive step)
 *
 * The expensive step is optional: the surfaces run 1+2 synchronously for an instant readiness read,
 * then hand 3 to a queued job so a web request never blocks on the feed fan-out. This does NOT run
 * keyword discovery (DataForSEO) — that has its own gated action; here we assume keywords exist and
 * light the rest of the fuse.
 */
class BlogPopulator
{
    public function __construct(
        private readonly KeywordRebucketer $rebucketer,
        private readonly GeneratedFeedReconciler $reconciler,
        private readonly FeedIngestor $ingestor,
    ) {}

    public function populate(Site $site, bool $ingest = true): BlogPopulationReport
    {
        $rebucketed = $this->rebucketer->rebucket($site);
        $feeds = $this->reconciler->reconcile($site);

        $ingestResult = $ingest ? $this->ingestor->ingestSite($site) : null;

        return new BlogPopulationReport(
            keywordsTotal: $this->keywordCount($site),
            keywordsSiloed: $this->keywordCount($site, siloedOnly: true),
            rebucketed: $rebucketed,
            feedsActive: $this->activeFeedCount($site),
            feedsUpserted: $feeds['upserted'],
            ingested: $ingest,
            fetched: $ingestResult['fetched'] ?? 0,
            candidatesCreated: $ingestResult['routed'] ?? 0,
            parked: $ingestResult['parked'] ?? 0,
        );
    }

    private function keywordCount(Site $site, bool $siloedOnly = false): int
    {
        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->when($siloedOnly, fn ($q) => $q->whereNotNull('silo_id'))
            ->count();
    }

    private function activeFeedCount(Site $site): int
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->whereNotNull('url')
            ->count();
    }
}
