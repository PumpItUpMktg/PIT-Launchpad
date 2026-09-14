<?php

namespace App\TownRank;

/**
 * The per-town "what do I do about it" (§ Town Rank, PR 2): a fixed-order rule list over a town's detail —
 * page state, the two organic ranks, who sits above us, the map-pack rank — producing the suggested actions
 * an operator works from. Deliberately mechanical and ordered by leverage: build the page before tuning it,
 * anchor it before judging it, fix "not ranking at all" before "rank 7". Pure; no I/O.
 */
final class TownDiagnosis
{
    /**
     * @param  array{
     *     page_state: string,
     *     local: array{rank: int|null, state: string, competitors: list<array{position: int, domain: string, url: string}>},
     *     town_query: array{rank: int|null, state: string, competitors: list<array{position: int, domain: string, url: string}>},
     *     map_rank: int|null,
     *     map_scanned: bool
     * }  $town
     * @return list<array{key: string, level: string, title: string, why: string}>
     */
    public static function for(array $town): array
    {
        $actions = [];
        $tq = $town['town_query'];
        $local = $town['local'];
        $above = fn (array $mode): string => implode(', ', array_slice(array_map(fn (array $c): string => $c['domain'], $mode['competitors']), 0, 3));

        // 1. The page itself.
        if ($town['page_state'] === 'none') {
            $actions[] = self::action('build_page', 'do', 'Build a town page',
                'No published page for this town.'.($tq['rank'] !== null ? ' Google is serving another of your pages (#'.$tq['rank'].') for the town search — a dedicated page can take that slot.' : ' Nothing of yours ranks for the town search.'));
        } elseif ($town['page_state'] === 'unanchored') {
            $actions[] = self::action('anchor_page', 'do', 'Anchor the page to its Census GEOID',
                'A page with this town\'s slug is published but not anchored, so proof, neighbours, and this report can\'t join it to the town. Run launchpad:anchor-town-pages.');
        }

        // 2. The explicit town search — the page's own query.
        if ($town['page_state'] !== 'none') {
            if ($tq['state'] === 'unscanned' || $tq['state'] === 'pending') {
                $actions[] = self::action('scan_town_query', 'watch', 'No town-query scan yet', 'Run the town-query mode to see whether the page ranks for its own town search.');
            } elseif ($tq['rank'] === null) {
                $actions[] = self::action('not_ranking_own_town', 'do', 'The town page is not ranking for its own town search',
                    'Check the page is indexed, and that its title and H1 name this town. Until it ranks at all, proof and links won\'t move it.');
            } elseif ($tq['rank'] > 10) {
                $actions[] = self::action('strengthen_page', 'do', 'Strengthen the page (#'.$tq['rank'].' for the town search)',
                    'Add proof near the town (jobs, reviews) and internal links from the hub and neighbouring towns.'.($above($tq) !== '' ? ' Above you: '.$above($tq).'.' : ''));
            } elseif ($tq['rank'] > 3) {
                $actions[] = self::action('close_gap', 'do', 'Close the gap to the top 3 (#'.$tq['rank'].')',
                    $above($tq) !== '' ? 'Outranked by '.$above($tq).' — compare their page against yours: proof, specificity, links.' : 'Page 1 already; more proof and links to push into the top 3.');
            } else {
                $actions[] = self::action('hold', 'ok', 'Holding the top 3 for the town search (#'.$tq['rank'].')', 'Keep the page fresh; watch for movement on the next sweep.');
            }
        }

        // 3. The plain local search — what a resident sees without typing the town.
        if ($local['state'] === 'unscanned' || $local['state'] === 'pending') {
            $actions[] = self::action('scan_local', 'watch', 'No local scan yet', 'Run the local mode to see the plain-search view from this town.');
        } elseif ($local['rank'] === null || $local['rank'] > 10) {
            $actions[] = self::action('local_weak', 'watch', 'Plain-search visibility is weak here'.($local['rank'] !== null ? ' (#'.$local['rank'].')' : ''),
                'A bare service search from this town is won by proximity and the map pack more than by the page.'.($above($local) !== '' ? ' Ahead: '.$above($local).'.' : ''));
        } else {
            $actions[] = self::action('local_ok', 'ok', 'On page 1 for a plain search from this town (#'.$local['rank'].')', 'Organic is doing its part here.');
        }

        // 4. The map pack.
        if (! $town['map_scanned']) {
            $actions[] = self::action('no_map_scan', 'watch', 'No map-pack scan for this town', 'Run a coverage scan for this keyword to see the GBP\'s rank here.');
        } elseif ($town['map_rank'] === null) {
            $actions[] = self::action('map_absent', 'do', 'Not in the map pack here', 'The GBP does not appear within depth from this town — distance from the listing, or a listing/category gap.');
        } elseif ($town['map_rank'] <= 3) {
            $actions[] = self::action('map_ok', 'ok', 'In the map-pack top 3 here (#'.$town['map_rank'].')', 'The listing carries this town.');
        } else {
            $actions[] = self::action('map_weak', 'watch', 'Map pack #'.$town['map_rank'].' here', 'Visible but below the 3-pack; reviews and proximity move this.');
        }

        return $actions;
    }

    /** @return array{key: string, level: string, title: string, why: string} */
    private static function action(string $key, string $level, string $title, string $why): array
    {
        return ['key' => $key, 'level' => $level, 'title' => $title, 'why' => $why];
    }
}
