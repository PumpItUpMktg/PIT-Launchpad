<?php

namespace App\KeywordGenerator\Cluster;

use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Part 2 orchestrator — clusters a tenant's corpus by demand: embed + geometry cluster
 * ({@see ClusterEngine}) → Claude label/merge/split/drop ({@see ClusterLabeler}) → head-term selection
 * ({@see HeadTermSelector}) → SERP-overlap validation on head candidates only ({@see SerpOverlapValidator}).
 * Persists `keyword_clusters` and stamps each corpus row's `cluster_id`. Re-runnable: prior clusters are
 * cleared first (operator dispositions on the corpus rows survive — dismissed terms are excluded).
 */
final class ClusteringPipeline
{
    public function __construct(
        private readonly ClusterEngine $engine,
        private readonly ClusterLabeler $labeler,
        private readonly HeadTermSelector $heads,
        private readonly SerpOverlapValidator $serp,
        private readonly IntentClassifier $intent,
    ) {}

    public function cluster(Site $site): ClusteringResult
    {
        /** @var list<KeywordCorpus> $terms */
        $terms = KeywordCorpus::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNull('disposition')->orWhere('disposition', '!=', 'dismissed'))
            ->get()
            ->all();

        // Re-run: clear the prior derivation input (clusters + assignments), keep corpus + dispositions.
        KeywordCluster::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->delete();
        KeywordCorpus::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->update(['cluster_id' => null]);

        if ($terms === []) {
            return new ClusteringResult(0, 0, 0);
        }

        $labeled = $this->labeler->label($this->engine->cluster($terms));

        $created = 0;
        $dropped = 0;
        foreach ($labeled as $cluster) {
            if ($cluster->offTrade) {
                $dropped++;

                continue;
            }

            $head = $this->heads->select($cluster->members);
            $serpStatus = $this->serp->validate($this->heads->candidates($cluster->members, 2));

            $record = new KeywordCluster;
            $record->forceFill([
                'site_id' => $site->id,
                'label' => $cluster->label,
                'head_term' => $head?->term,
                'head_canonical' => $head?->canonical,
                'intent' => $head !== null ? ($head->intent ?? $this->intent->classify($head->term)->value) : null,
                'volume' => $head?->volume,
                'member_count' => count($cluster->members),
                'dropped' => false,
                'serp_status' => $serpStatus,
            ])->save();

            KeywordCorpus::withoutGlobalScope(SiteScope::class)
                ->whereIn('id', array_map(fn (KeywordCorpus $m): string => $m->id, $cluster->members))
                ->update(['cluster_id' => $record->id]);

            $created++;
        }

        Log::info('keyword-first clustering', [
            'site_id' => $site->id,
            'clusters' => $created,
            'dropped_off_trade' => $dropped,
            'serp_calls' => $this->serp->callCount(),
        ]);

        return new ClusteringResult($created, $dropped, $this->serp->callCount());
    }
}
