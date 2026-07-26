<?php

namespace App\ContentEngine\Reconcile;

use App\Build\PlanSync;
use App\Build\ServiceStructureWriter;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Jobs\SyncSiloCategories;
use App\KeywordGenerator\KeywordRebucketer;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The orchestrated "Rebuild & reconcile" cascade (§B slice 4) — the single entry point that re-aligns a
 * site's downstream references to its current silo tree, in dependency order, each stage idempotent and
 * failure-isolated (a hiccup in one stage records an error and the cascade continues). It wires the
 * existing per-edge reconcilers rather than reimplementing them; the whole thing is safe to re-run.
 *
 * Two modes:
 * - **Reconcile** (default) — re-align the downstream to the CURRENT structure: re-bucket orphaned
 *   keywords, ensure silo→WP categories, re-route posts, re-tag towns, then bounded republish. Light,
 *   no structural change, no AI.
 * - **Rebuild structure** (opt-in) — first rewrite the §4 blueprint from the authored Service tree and
 *   re-materialize pages (which re-pins each page's silo_id + derives rule_sets), THEN reconcile. This
 *   is the heavier, deliberate path — the operator chooses it when the structure itself changed.
 *
 * Republish is **bounded** (D5): only the live posts whose silo/category changed and the live location
 * pages whose town feed changed are queued (idempotent by ULID, on the same worker + monitor), never a
 * blanket repush of every page.
 */
final class RebuildReconciler
{
    public function __construct(
        private readonly KeywordRebucketer $rebucketer,
        private readonly PostSiloReconciler $postReconciler,
        private readonly PostTownTagger $townTagger,
        private readonly ServiceStructureWriter $structureWriter,
        private readonly PlanSync $planSync,
    ) {}

    public function reconcile(Site $site, bool $rebuildStructure = false): RebuildReport
    {
        $report = new RebuildReport;

        // Stage 1–2 (opt-in) — rewrite the structure from services, then re-materialize pages so each
        // page's silo_id re-pins and the current silos + rule_sets exist for the re-bucket below.
        if ($rebuildStructure) {
            $this->stage($report, 'structure', function () use ($site, $report): void {
                $blueprint = $this->structureWriter->write($site);
                $report->structureRebuilt = true;
                $report->spokes = $blueprint->spokes()->count();
            });
            $this->stage($report, 'pages', function () use ($site, $report): void {
                $report->pagesAdded = $this->planSync->sync($site);
            });
        }

        // Stage 3 — re-file orphaned (silo_id null) keyword targets onto the current silos' rule_sets.
        $this->stage($report, 'keywords', function () use ($site, $report): void {
            $report->keywordsRebucketed = $this->rebucketer->rebucket($site);
        });

        // Stage 4 — ensure every current silo has its WordPress category term (async; no-ops without WP).
        $this->stage($report, 'categories', function () use ($site, $report): void {
            SyncSiloCategories::enqueue($site);
            $report->categoriesQueued = true;
        });

        // Stage 5a — re-route posts to a live silo (the Uncategorized fix). Capture the rerouted ids for
        // the bounded republish.
        $reroutedIds = [];
        $this->stage($report, 'posts', function () use ($site, $report, &$reroutedIds): void {
            $r = $this->postReconciler->reconcile($site);
            $report->postsRerouted = count($r['rerouted']);
            $report->postsOrphaned = $r['orphaned'];
            $report->postsUnchanged = $r['unchanged'];
            $reroutedIds = $r['rerouted'];
        });

        // Stage 5b — re-tag posts with the coverage towns they mention. Capture the changed town keys so
        // only the location pages whose local feed actually changed get republished.
        $changedTowns = [];
        $this->stage($report, 'towns', function () use ($site, $report, &$changedTowns): void {
            $t = $this->townTagger->tag($site);
            $report->townsTagged = $t['posts_tagged'];
            $report->tagsAdded = $t['tags_added'];
            $report->tagsRemoved = $t['tags_removed'];
            $changedTowns = $t['changed_towns'];
        });

        // Stage 6 — bounded republish of the affected live content only.
        $this->stage($report, 'republish', function () use ($site, $report, $reroutedIds, $changedTowns): void {
            $rp = $this->republish($site, $reroutedIds, $changedTowns);
            $report->republishedPosts = $rp['posts'];
            $report->republishedLocationPages = $rp['location_pages'];
        });

        return $report;
    }

    /** Run a stage, isolating its failure so the cascade always completes and reports the error. */
    private function stage(RebuildReport $report, string $stage, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $report->fail($stage, $e->getMessage());
            Log::error("Rebuild & reconcile stage [{$stage}] failed", ['exception' => $e]);
        }
    }

    /**
     * Queue only the live content whose category/feed actually changed: the rerouted published posts
     * (their silo category moved) and the published location pages whose town gained/lost a post (their
     * baked local feed is now stale). Idempotent by ULID.
     *
     * @param  list<string>  $reroutedIds
     * @param  list<string>  $changedTowns  normalized town keys
     * @return array{posts: int, location_pages: int}
     */
    private function republish(Site $site, array $reroutedIds, array $changedTowns): array
    {
        $postIds = $reroutedIds === [] ? collect() : Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $reroutedIds)
            ->where('status', ContentStatus::Published->value)
            ->pluck('id');

        foreach ($postIds as $id) {
            PublishContent::dispatch((string) $id);
        }

        $pages = 0;
        if ($changedTowns !== []) {
            $changed = array_flip($changedTowns);
            $locationPages = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('kind', ContentKind::Page->value)
                ->where('page_type', PageType::Location->value)
                ->where('status', ContentStatus::Published->value)
                ->whereNotNull('wp_post_id')
                ->get(['id', 'title']);

            foreach ($locationPages as $page) {
                if (isset($changed[$this->townKey((string) $page->title)])) {
                    PublishContent::dispatch((string) $page->id);
                    $pages++;
                }
            }
        }

        return ['posts' => $postIds->count(), 'location_pages' => $pages];
    }

    /** Normalize a town label to the key the tagger writes: drop a trailing ", ST", lower-case. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
