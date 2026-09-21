<?php

namespace App\Publishing\Redirects;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishContentService;
use Illuminate\Support\Str;

/**
 * Turns the high-value informational legacy URLs into reviewable blog candidates
 * instead of pillar redirects. It pools the redirect planner's UNRESOLVED URLs
 * (no live equivalent) with its approximate pillar matches (`slug_overlap`), then
 * GROUPS them into families (an old-site article and its numbered duplicates —
 * `…-cost-breakdown-3/-4/-8` → one post) so a family is revived whole, never split
 * between redirect and revival:
 *
 *  - an unresolved family revives once its total clears the `min_impressions`
 *    floor (no pillar wanted it anyway);
 *  - a family the planner matched by RESEMBLANCE (`slug_overlap` or `top_query`)
 *    is revived only when it's high-value — total ≥ `divert_floor` — otherwise it
 *    stays a redirect. Both rungs guess; `top_query` simply guesses more
 *    confidently, which is what makes it the more dangerous of the two.
 *
 * Each revived candidate carries the family's winning GSC query as the brief and
 * remembers ALL its source URLs in `meta.revived_from_urls`; the operator
 * generates it through the normal gated flow (this NEVER drafts), and on publish
 * {@see PublishContentService} 301s every one of those old URLs to
 * the new post. The planner skips any URL a candidate has claimed, so the two
 * commands coordinate and never double-handle a URL.
 */
class LegacyContentReviver
{
    /** Cascade rungs that matched on resemblance, so a high-value family may be kept rather than routed. */
    private const DIVERTABLE = ['slug_overlap', 'top_query'];

    public function __construct(private readonly LegacyRedirectPlanner $planner) {}

    /**
     * The revival families a run WOULD create (dry view), highest total impressions first, capped.
     *
     * @return list<array{key: string, from_urls: list<string>, query: ?string, impressions: int}>
     */
    public function plan(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        return $this->compute($site, $minImpressions, $limit)['families'];
    }

    /**
     * Why a run produced what it produced — the pool it started from, the families it formed, and how many
     * fell to each filter with the thresholds that did it.
     *
     * An empty plan has at least five different causes (nothing unresolved, everything already claimed, a
     * raised floor, the divert floor, a limit of zero) and "nothing above the impression floor (or all
     * already revived)" distinguishes none of them. On Sump Pump Gurus the redirect plan showed 458
     * unresolved URLs, several above 70,000 impressions, and this returned nothing — a sentence that says
     * "or" cannot be acted on.
     *
     * @return array{
     *     unresolved: int, divertable: int, claimed: int, families: int,
     *     below_floor: int, below_floor_impressions: int, below_floor_bands: array<string, array{families: int, impressions: int}>,
     *     below_divert_floor: int, capped: int,
     *     floor: int, divert_floor: int, cap: int
     * }
     */
    public function diagnose(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        return $this->compute($site, $minImpressions, $limit)['stats'];
    }

    /**
     * @return array{
     *     families: list<array{key: string, from_urls: list<string>, query: ?string, impressions: int}>,
     *     stats: array{
     *         unresolved: int, divertable: int, claimed: int, families: int,
     *         below_floor: int, below_floor_impressions: int, below_floor_bands: array<string, array{families: int, impressions: int}>,
     *         below_divert_floor: int, capped: int, floor: int, divert_floor: int, cap: int
     *     }
     * }
     */
    private function compute(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        $floor = $minImpressions ?? (int) config('launchpad.legacy_revival.min_impressions', 5000);
        $divertFloor = (int) config('launchpad.legacy_revival.divert_floor', 20000);
        $cap = $limit ?? (int) config('launchpad.legacy_revival.limit', 100);

        $planned = $this->planner->plan($site);

        // Pool the revival-eligible URLs: unresolved (no pillar) + approximate pillar matches.
        $pool = [];
        foreach ($planned['unresolved'] as $u) {
            $pool[] = ['from' => $u['from'], 'query' => $u['top_query'], 'impressions' => $u['impressions'], 'unresolved' => true];
        }
        // Divertable: the rungs of the cascade that matched on RESEMBLANCE rather than identity.
        //
        // `slug_overlap` is an approximate token match. `top_query` looks more confident and is the more
        // dangerous of the two: "how to install a sump pump correctly" matching the installation page's
        // target keyword is exactly where high keyword similarity hides an intent mismatch — the query
        // wants an article and the successor is a hire-us page. Sump Pump Gurus had 172,970 impressions
        // in that shape, confidently routed onto a service page that could never have ranked for them.
        //
        // `town` and `numbered_duplicate` are NOT divertable: a town URL genuinely belongs on the town
        // page, and a true copy of a live page genuinely should collapse onto its original.
        foreach ($planned['redirect'] as $r) {
            if (in_array($r['reason'], self::DIVERTABLE, true)) {
                $pool[] = ['from' => $r['from'], 'query' => $r['top_query'], 'impressions' => $r['impressions'], 'unresolved' => false];
            }
        }

        // Group into families by base path (collision suffix stripped) so a numbered dup set is one post.
        $families = [];
        foreach ($pool as $row) {
            $key = CollisionSuffix::strip((string) $row['from']) ?? (string) $row['from'];
            $fam = $families[$key] ?? ['key' => $key, 'members' => [], 'impressions' => 0, 'has_unresolved' => false];
            $fam['members'][] = ['from' => $row['from'], 'query' => $row['query'], 'impressions' => $row['impressions']];
            $fam['impressions'] += $row['impressions'];
            $fam['has_unresolved'] = $fam['has_unresolved'] || $row['unresolved'];
            $families[$key] = $fam;
        }

        $out = [];
        $belowFloor = 0;
        $belowFloorImpressions = 0;
        $belowFloorBands = [];
        $belowDivertFloor = 0;
        foreach ($families as $fam) {
            if ($fam['impressions'] < $floor) {
                // How much is in the tail, and how it is distributed — "274 families fell below the floor"
                // is 274 x 50 impressions or 274 x 4,900, and those are opposite decisions about whether
                // to lower it. A count alone cannot be acted on.
                $belowFloor++;
                $belowFloorImpressions += $fam['impressions'];
                $band = $this->band($fam['impressions'], $floor);
                $belowFloorBands[$band]['families'] = ($belowFloorBands[$band]['families'] ?? 0) + 1;
                $belowFloorBands[$band]['impressions'] = ($belowFloorBands[$band]['impressions'] ?? 0) + $fam['impressions'];

                continue;
            }
            if (! $fam['has_unresolved'] && $fam['impressions'] < $divertFloor) {
                $belowDivertFloor++;

                continue; // a pillar match that isn't high-value enough to steal from the redirect
            }

            // Order members by impressions so the top one names the family (query + primary URL).
            usort($fam['members'], fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
            $out[] = [
                'key' => $fam['key'],
                'from_urls' => array_map(fn (array $m): string => (string) $m['from'], $fam['members']),
                'query' => $fam['members'][0]['query'] ?? null,
                'impressions' => $fam['impressions'],
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        $stats = [
            'unresolved' => count($planned['unresolved']),
            'divertable' => count($pool) - count($planned['unresolved']),
            'claimed' => Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereNotNull('meta->revived_from_urls')
                ->count(),
            'families' => count($families),
            'below_floor' => $belowFloor,
            'below_floor_impressions' => $belowFloorImpressions,
            'below_floor_bands' => $belowFloorBands,
            'below_divert_floor' => $belowDivertFloor,
            'capped' => max(0, count($out) - $cap),
            'floor' => $floor,
            'divert_floor' => $divertFloor,
            'cap' => $cap,
        ];

        return ['families' => array_slice($out, 0, $cap), 'stats' => $stats];
    }

    /**
     * Where a below-floor family sits, in fractions of the floor itself so the bands mean something
     * whatever the floor is set to.
     */
    private function band(int $impressions, int $floor): string
    {
        $floor = max(1, $floor);

        return match (true) {
            $impressions >= (int) ($floor * 0.5) => 'half the floor to the floor',
            $impressions >= (int) ($floor * 0.2) => 'a fifth to a half',
            $impressions >= (int) ($floor * 0.05) => 'a twentieth to a fifth',
            default => 'negligible',
        };
    }

    /**
     * Create one blog candidate per revival family (status `candidate`, gated for operator generation).
     *
     * @return list<Content>
     */
    public function revive(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        $created = [];
        foreach ($this->plan($site, $minImpressions, $limit) as $family) {
            $query = is_string($family['query']) && trim($family['query']) !== ''
                ? trim($family['query'])
                : $this->titleFromSlug($family['from_urls'][0]);
            $title = Str::title($query);
            $count = count($family['from_urls']);

            $created[] = Content::create([
                'site_id' => $site->id,
                'kind' => ContentKind::Post,
                'intake_type' => IntakeType::Reactive,
                'draft_trigger' => DraftTrigger::OnDemand,
                'status' => ContentStatus::Candidate,
                'title' => $title,
                'slug' => $this->uniqueSlug($site->id, $title),
                'source_name' => 'Legacy revival (GSC)',
                'source_url' => $family['from_urls'][0],
                'angle_hint' => sprintf(
                    'Revive a top-performing legacy article. Write a comprehensive, up-to-date post targeting the query “%s” (the old %s earned %s impressions for it). On publish, %s 301%s to this post.',
                    $query,
                    $count === 1 ? 'URL' : "{$count} URLs",
                    number_format($family['impressions']),
                    $count === 1 ? 'the old URL' : "all {$count} old URLs",
                    $count === 1 ? 's' : '',
                ),
                'version' => 1,
                'meta' => [
                    'revived_from_urls' => $family['from_urls'],
                    'revived_query' => $query,
                    'revived_impressions' => $family['impressions'],
                ],
            ]);
        }

        return $created;
    }

    private function titleFromSlug(string $from): string
    {
        $leaf = (string) Str::of($from)->trim('/')->afterLast('/')->replace('-', ' ');

        return $leaf === '' ? 'Legacy article' : $leaf;
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'legacy-post';
        $slug = $base;
        $n = 1;
        while (Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
