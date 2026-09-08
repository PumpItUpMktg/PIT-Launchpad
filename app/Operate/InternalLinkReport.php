<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\LinkFindingType;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\Links\InternalLinkAuditor;
use App\Publishing\Links\InternalLinkGraph;
use App\Publishing\Links\LinkPlanBuilder;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY analysis of a site's two internal-linking mechanisms against a target link policy — it writes
 * nothing and applies nothing. The two mechanisms are DIFFERENT sets:
 *
 *  - the AUDIT-opportunity set ({@see InternalLinkAuditor}) — the "New link available" findings: a page
 *    whose visible copy already NAMES another page's ranking term but doesn't link it. Relevance-gated
 *    (the copy cites the term) and capped at 3/page by the auditor.
 *  - the PLAN spine ({@see LinkPlanBuilder::previewAll}) — the five-source proposed inbound links to town
 *    pages (Mesh/Market/JobReview/Blog/Areas), previewed without persisting.
 *
 * For each set it reports the shape against the policy the operator is deciding on: the direction
 * breakdown (town→town / market→town / post→page / areas→town), the reciprocal-pair count (A→B AND B→A —
 * the link-wheel signature), the projected TOTAL outbound per source after applying (existing links the
 * page already carries + proposed adds) against a word-scaled cap, and how many target pages already rank
 * top 3 (and so need no link). Nothing here decides; it measures so the decision is made on numbers.
 *
 * Caveat surfaced by the command: "existing outbound" is every internal link the graph models (structural
 * spine + contextual body), while the word-scaled cap targets contextual body links — so a projected total
 * is an UPPER bound. The load-bearing signal is which sources blow past the cap regardless (the Areas page,
 * a market landing that appears in every plan), which this surfaces cleanly either way.
 */
class InternalLinkReport
{
    private const WINDOW_DAYS = 28;

    /** The policy under test: ~1 contextual body link per N words, hard ceiling. */
    private const WORDS_PER_LINK = 150;

    private const OUTBOUND_CEILING = 10;

    public function __construct(
        private readonly InternalLinkGraph $graph,
        private readonly InternalLinkAuditor $auditor,
        private readonly LinkPlanBuilder $planner,
    ) {}

    /**
     * @return array{
     *   policy: array{words_per_link: int, ceiling: int, inbound_target: string},
     *   audit: array<string, mixed>,
     *   plan: array<string, mixed>
     * }
     */
    public function forSite(Site $site): array
    {
        $graph = $this->graph->build($site);
        $position = $this->blendedPositionByContent($site, $graph);

        // Audit-opportunity edges: source page (contentId) → the page it names but doesn't link (suggested).
        $findings = $this->auditor->audit($site);
        $auditEdges = [];
        $orphans = 0;
        $deadEnds = 0;
        foreach ($findings as $f) {
            match ($f->type) {
                LinkFindingType::Orphan => $orphans++,
                LinkFindingType::DeadEnd => $deadEnds++,
                LinkFindingType::Opportunity => $f->suggestedContentId !== null
                    ? $auditEdges[] = ['source' => $f->contentId, 'target' => $f->suggestedContentId, 'type' => null]
                    : null,
            };
        }

        // Plan-spine edges: the whole spine previewAll would propose, without persisting.
        $planEdges = array_map(
            fn (array $c): array => ['source' => $c['source'], 'target' => $c['target'], 'type' => $c['type']->value],
            array_values(array_filter($this->planner->previewAll($site), fn (array $c): bool => $c['source'] !== null)),
        );

        return [
            'policy' => ['words_per_link' => self::WORDS_PER_LINK, 'ceiling' => self::OUTBOUND_CEILING, 'inbound_target' => '3–5, none for a top-3 page'],
            'audit' => $this->analyze($auditEdges, $graph, $position) + ['orphans' => $orphans, 'dead_ends' => $deadEnds],
            'plan' => $this->analyze($planEdges, $graph, $position, bySourceType: true),
        ];
    }

    /**
     * @param  list<array{source: string, target: string, type: ?string}>  $edges
     * @param  array<string, ?float>  $position  contentId => blended GSC position (null = untracked)
     * @return array<string, mixed>
     */
    private function analyze(array $edges, InternalLinkGraph $graph, array $position, bool $bySourceType = false): array
    {
        $keys = [];
        $breakdown = [];
        $bySource = [];
        $bySourceTypeCount = [];
        $targets = [];
        foreach ($edges as $e) {
            $keys[$e['source'].'→'.$e['target']] = true;
            $src = $graph->pages->get($e['source']);
            $tgt = $graph->pages->get($e['target']);
            $cat = $this->classify($src, $tgt);
            $breakdown[$cat] = ($breakdown[$cat] ?? 0) + 1;
            if ($bySourceType && $e['type'] !== null) {
                $bySourceTypeCount[$e['type']] = ($bySourceTypeCount[$e['type']] ?? 0) + 1;
            }
            $bySource[$e['source']] = ($bySource[$e['source']] ?? 0) + 1;
            $targets[$e['target']] = true;
        }

        // Reciprocal pairs: both A→B and B→A present, counted once per unordered pair.
        $reciprocal = 0;
        foreach (array_keys($keys) as $key) {
            [$a, $b] = explode('→', $key, 2);
            if ($a < $b && isset($keys[$b.'→'.$a])) {
                $reciprocal++;
            }
        }

        // Projected outbound per source after applying, against the word-scaled cap.
        $overCap = [];
        foreach ($bySource as $sourceId => $adds) {
            $page = $graph->pages->get($sourceId);
            $words = $page !== null ? str_word_count($graph->text($sourceId)) : 0;
            $cap = min(self::OUTBOUND_CEILING, intdiv($words, self::WORDS_PER_LINK));
            $existing = count($graph->outbound($sourceId));
            $projected = $existing + $adds;
            if ($projected > $cap) {
                $overCap[] = [
                    'source_id' => $sourceId,
                    'title' => $page !== null ? (string) $page->title : $sourceId,
                    'words' => $words,
                    'cap' => $cap,
                    'existing' => $existing,
                    'adds' => $adds,
                    'projected' => $projected,
                ];
            }
        }
        usort($overCap, fn (array $a, array $b): int => $b['projected'] <=> $a['projected']);

        $top3Targets = count(array_filter(
            array_keys($targets),
            fn (string $id): bool => ($position[$id] ?? null) !== null && $position[$id] <= 3.0,
        ));

        $out = [
            'total' => count($edges),
            'distinct_sources' => count($bySource),
            'distinct_targets' => count($targets),
            'breakdown' => $breakdown,
            'reciprocal_pairs' => $reciprocal,
            'over_cap_count' => count($overCap),
            'over_cap' => array_slice($overCap, 0, 15),
            'top3_targets' => $top3Targets,
        ];
        if ($bySourceType) {
            $out['by_source_type'] = $bySourceTypeCount;
        }

        return $out;
    }

    /** Classify an edge by the page types it connects (the operator's town/market/post vocabulary). */
    private function classify(?Content $src, ?Content $tgt): string
    {
        if ($src === null || $tgt === null) {
            return 'other';
        }
        $s = $this->role($src);
        $t = $this->role($tgt);

        return match (true) {
            $s === 'town' && $t === 'town' => 'town→town',
            $s === 'market' && $t === 'town' => 'market→town',
            $s === 'post' => 'post→page',
            $s === 'areas' && $t === 'town' => 'areas→town',
            default => "{$s}→{$t}",
        };
    }

    /** The operator role of a page: town / market (landing) / post / areas / other. */
    private function role(Content $c): string
    {
        if ($c->kind === ContentKind::Post) {
            return 'post';
        }
        if ($c->standard_type === StandardPageType::AreasWeServe) {
            return 'areas';
        }
        if ($c->page_type === PageType::Location) {
            if ($c->location_id !== null) {
                return 'market';
            }
            if ($c->parent_location_id !== null) {
                return 'town';
            }
        }

        return $c->page_type instanceof PageType ? $c->page_type->value : 'other';
    }

    /**
     * Blended GSC position per content id, over the trailing window: Σ(position×impressions) / Σimpressions
     * on positioned rows, matched by normalized path. Null when the page has no positioned impressions.
     *
     * @return array<string, ?float>
     */
    private function blendedPositionByContent(Site $site, InternalLinkGraph $graph): array
    {
        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->selectRaw('url,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $byPath = [];
        foreach ($rows as $row) {
            $path = UrlNormalizer::path((string) parse_url((string) $row->url, PHP_URL_PATH));
            $byPath[$path] ??= ['impr_pos' => 0, 'posw' => 0.0];
            $byPath[$path]['impr_pos'] += (int) $row->impr_pos;
            $byPath[$path]['posw'] += (float) $row->posw;
        }

        $domain = $site->domain_url;
        $out = [];
        foreach ($graph->pages as $id => $page) {
            $path = UrlNormalizer::path(PublicUrl::forContent($domain, $page) ?? '/'.ltrim((string) $page->slug, '/'));
            $stat = $byPath[$path] ?? null;
            $out[(string) $id] = $stat !== null && $stat['impr_pos'] > 0 ? $stat['posw'] / $stat['impr_pos'] : null;
        }

        return $out;
    }
}
