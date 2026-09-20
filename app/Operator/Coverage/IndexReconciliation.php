<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Guided\StoredSearchMetrics;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\ContentCard;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Why the Indexing board and the per-page cards disagree about the same site — counted, not guessed.
 *
 * The two surfaces answer the same question from different evidence, and neither is simply wrong:
 *
 *   • A per-page card (and the CLIENT dashboard) calls a page indexed when URL Inspection returned PASS
 *     **or** the page earned Search impressions. Impressions are proof: a page cannot be shown in results
 *     without being in the index. Their absence proves nothing, which is why it is an OR and never an AND.
 *   • The Indexing board counts PASS verdicts only, over the rows that exist in `page_index_states`.
 *
 * So they part company in two independent ways, and this counts both:
 *
 *   1. THE MISSING OR. A page with impressions but no PASS verdict reads "Indexed" on its card and lands
 *      in the board's not-indexed column — or nowhere at all, when it has never been inspected.
 *   2. DIFFERENT DENOMINATORS. Cards cover every published page; the board covers inspected pages. The
 *      inspector is budget-capped, so on a large site those are very different sets, and the board's
 *      percentages are over a subset it does not name in the same breath.
 *
 * A third, quieter difference: the card's impression signal is a {@see StoredSearchMetrics::WINDOW_DAYS}
 * window, while the client dashboard's is all-time. A page that earned impressions last quarter and none
 * since is indexed by one measure and unknown by the other. Counted separately below rather than folded
 * in, because it is a different question — "is it in the index" versus "is it still earning".
 *
 * Read-only and HTTP-free: `page_index_states`, `gsc_url_daily` and `contents`. The card side is resolved
 * by calling {@see ContentCard::resolveIndex} itself, not by re-deriving it — a reimplementation could be
 * wrong in exactly the way it is meant to detect.
 */
class IndexReconciliation
{
    /**
     * @return array{
     *     board: array{inspected: int, indexed: int, not_indexed: int, excluded: int, gap: int},
     *     cards: array{published: int, indexed: int, not_indexed: int, unchecked: int},
     *     disagreements: int,
     *     causes: array{
     *         impressions_but_verdict_says_no: list<array{title: string, url: ?string, verdict: string}>,
     *         impressions_but_never_inspected: list<array{title: string, url: ?string, verdict: string}>,
     *         never_inspected_total: int,
     *         pass_without_recent_impressions: int,
     *         impressions_outside_window: int
     *     },
     *     window_days: int
     * }
     */
    public function for(Site $site, int $examples = 20): array
    {
        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type']);

        $verdicts = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('content_id')
            ->pluck('index_verdict', 'content_id');

        [$recent, $everSeen] = $this->impressionIds($site, $published);

        $board = app(IndexStandings::class)->for($site->id);

        $cards = ['published' => $published->count(), 'indexed' => 0, 'not_indexed' => 0, 'unchecked' => 0];
        $withRow = [];
        $withoutRow = [];
        $passNoImpressions = 0;
        $outsideWindow = 0;

        foreach ($published as $page) {
            $id = (string) $page->id;
            $verdict = $verdicts->has($id) ? (string) ($verdicts[$id] ?? '') : null;
            $isPass = $verdict === 'PASS';
            $inGoogle = isset($recent[$id]);

            [, $state] = ContentCard::resolveIndex($isPass, $verdict !== null, $inGoogle);
            $cards[$state === 'indexed' ? 'indexed' : ($state === 'not_indexed' ? 'not_indexed' : 'unchecked')]++;

            if ($isPass && ! $inGoogle) {
                $passNoImpressions++;
            }
            if (! $inGoogle && isset($everSeen[$id])) {
                $outsideWindow++;
            }

            // The disagreement itself: the card says indexed on the strength of impressions, the board
            // does not, because the verdict is missing or is not PASS.
            if ($inGoogle && ! $isPass) {
                $row = [
                    'title' => (string) $page->title,
                    'url' => PublicUrl::forContent($site->domain_url, $page),
                    'verdict' => $verdict === null || $verdict === '' ? 'never inspected' : $verdict,
                ];
                if ($verdict === null) {
                    $withoutRow[] = $row;
                } else {
                    $withRow[] = $row;
                }
            }
        }

        return [
            'board' => [
                'inspected' => $board['inspected_count'],
                'indexed' => $board['published']['indexed'],
                'not_indexed' => $board['published']['not_indexed'],
                'excluded' => $board['published']['excluded'],
                'gap' => $board['coverage_gap'],
            ],
            'cards' => $cards,
            'disagreements' => count($withRow) + count($withoutRow),
            'causes' => [
                'impressions_but_verdict_says_no' => array_slice($withRow, 0, $examples),
                'impressions_but_never_inspected' => array_slice($withoutRow, 0, $examples),
                'never_inspected_total' => $published->count() - $verdicts->count(),
                'pass_without_recent_impressions' => $passNoImpressions,
                'impressions_outside_window' => $outsideWindow,
            ],
            'window_days' => StoredSearchMetrics::WINDOW_DAYS,
        ];
    }

    /**
     * Which published pages earned Search impressions — inside the card's window, and ever.
     *
     * Both forms of every URL are looked up (slash and slashless) for the same reason
     * {@see StoredSearchMetrics} does it: Search Console stores the canonical URL as it comes back, and
     * which form a site's permalinks settled on is not ours to assume.
     *
     * @param  Collection<int, Content>  $published
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function impressionIds(Site $site, $published): array
    {
        $byUrl = [];
        foreach ($published as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url === null) {
                continue;
            }
            $id = (string) $page->id;
            $byUrl[rtrim($url, '/')] = $id;
            $byUrl[rtrim($url, '/').'/'] = $id;
        }
        if ($byUrl === []) {
            return [[], []];
        }

        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', array_keys($byUrl))
            ->where('impressions', '>', 0)
            ->selectRaw('url, max(date) as newest')
            ->groupBy('url')
            ->get();

        $cutoff = Carbon::now()->subDays(StoredSearchMetrics::WINDOW_DAYS)->toDateString();
        $recent = [];
        $ever = [];
        foreach ($rows as $row) {
            $id = $byUrl[(string) $row->url] ?? null;
            if ($id === null) {
                continue;
            }
            $ever[$id] = true;
            if (Carbon::parse((string) $row->newest)->toDateString() >= $cutoff) {
                $recent[$id] = true;
            }
        }

        return [$recent, $ever];
    }
}
