<?php

namespace App\Operator\Coverage;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pages Google holds but never shows — indexed, and earning nothing in Search.
 *
 * Being indexed is not the same as being found, and the gap between those two is where a content
 * programme quietly fails. A third of Sump Pump Gurus' published set sits in it. The point of this read
 * model is to stop that number reading as one problem when it is at least four, most of which are not
 * problems at all:
 *
 *   • TOO YOUNG. Published last week. Google has it; nobody has searched into it yet. Nothing is wrong,
 *     and counting it as a failure makes the real number look worse than it is.
 *   • NO DEMAND. The page targets a term with no meaningful search volume — a hamlet of four hundred
 *     people, a phrase nobody types. Zero impressions is the CORRECT outcome. The page may still earn its
 *     keep as topical coverage and internal-link surface; it was never going to earn impressions.
 *   • LAPSED. It used to earn impressions and stopped. That is a different event from never starting —
 *     a ranking lost, a competitor moved, a page decayed — and the only one with a before to compare to.
 *   • ORPHANED. Nothing links to it. Google can reach it via the sitemap and still rank it nowhere,
 *     because no internal signal says it matters.
 *
 * The actionable set is the intersection: old enough to have had a chance, targeting a term with real
 * volume, and still earning nothing. Everything else is context that stops that set being over-read.
 *
 * Read-only and HTTP-free.
 */
class QuietPages
{
    /** Below this, a page has not had a fair chance yet. */
    private const YOUNG_DAYS = 30;

    /** Monthly searches below which zero impressions is the expected outcome, not a defect. */
    private const THIN_DEMAND = 10;

    /**
     * @return array{
     *     total: int,
     *     indexed_total: int,
     *     never_earned: int,
     *     lapsed: int,
     *     too_young: int,
     *     no_inbound_links: int,
     *     by_type: array<string, int>,
     *     by_demand: array{no_target: int, thin: int, real: int},
     *     actionable: list<array{title: string, url: ?string, type: string, days: ?int, volume: ?int, inbound: int, lapsed: bool}>
     * }
     */
    public function for(Site $site, int $examples = 25): array
    {
        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type', 'published_at', 'target_keyword_id']);

        [$recent, $ever] = app(PageImpressions::class)->resolve($site, $published);

        $verdicts = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('content_id')
            ->pluck('index_verdict', 'content_id');

        $volumes = $this->volumes($site, $published);
        $graph = app(InternalLinkGraph::class)->build($site);

        $out = [
            'total' => 0, 'indexed_total' => 0, 'never_earned' => 0, 'lapsed' => 0, 'too_young' => 0,
            'no_inbound_links' => 0, 'by_type' => [],
            'by_demand' => ['no_target' => 0, 'thin' => 0, 'real' => 0],
        ];
        $actionable = [];

        foreach ($published as $page) {
            $id = (string) $page->id;
            $indexed = ($verdicts[$id] ?? null) === 'PASS' || isset($ever[$id]);
            if (! $indexed) {
                continue;
            }
            $out['indexed_total']++;
            if (isset($recent[$id])) {
                continue;   // earning, nothing to explain
            }

            $out['total']++;
            $lapsed = isset($ever[$id]);
            $out[$lapsed ? 'lapsed' : 'never_earned']++;

            $days = $page->published_at !== null ? (int) $page->published_at->diffInDays(Carbon::now()) : null;
            $young = $days !== null && $days < self::YOUNG_DAYS;
            if ($young) {
                $out['too_young']++;
            }

            $type = $this->typeOf($page);
            $out['by_type'][$type] = ($out['by_type'][$type] ?? 0) + 1;

            $volume = $page->target_keyword_id !== null ? ($volumes[(string) $page->target_keyword_id] ?? null) : null;
            $demand = $page->target_keyword_id === null
                ? 'no_target'
                : (($volume ?? 0) < self::THIN_DEMAND ? 'thin' : 'real');
            $out['by_demand'][$demand]++;

            $inbound = count($graph->inbound($id));
            if ($inbound === 0) {
                $out['no_inbound_links']++;
            }

            // The intersection worth acting on: had its chance, has demand to win, still silent.
            if (! $young && $demand === 'real') {
                $actionable[] = [
                    'title' => (string) $page->title,
                    'url' => PublicUrl::forContent($site->domain_url, $page),
                    'type' => $type,
                    'days' => $days,
                    'volume' => $volume,
                    'inbound' => $inbound,
                    'lapsed' => $lapsed,
                ];
            }
        }

        // Worst first: no inbound links, then highest volume going to waste.
        usort($actionable, fn (array $a, array $b): int => [$a['inbound'], -($b['volume'] ?? 0)] <=> [$b['inbound'], -($a['volume'] ?? 0)]);

        arsort($out['by_type']);
        $out['actionable'] = array_slice($actionable, 0, $examples);

        return $out;
    }

    /**
     * Monthly search volume per target keyword, id => volume.
     *
     * @param  Collection<int, Content>  $pages
     * @return array<string, int|null>
     */
    private function volumes(Site $site, Collection $pages): array
    {
        $ids = $pages->pluck('target_keyword_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('id', $ids->all())
            ->pluck('volume', 'id')
            ->map(fn ($v): ?int => $v === null ? null : (int) $v)
            ->all();
    }

    /** The lane a page belongs to, in the words the boards use. */
    private function typeOf(Content $page): string
    {
        if ($page->kind === ContentKind::Post) {
            return 'blog';
        }

        return match ($page->page_type) {
            PageType::Location => 'town',
            PageType::Service => 'service',
            PageType::Hub => 'hub',
            PageType::Home, PageType::Utility => 'core',
            default => 'core',
        };
    }
}
