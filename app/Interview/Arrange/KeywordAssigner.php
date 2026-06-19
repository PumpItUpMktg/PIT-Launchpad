<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Pass D — cannibalization-safe keyword assignment. Every page (pillar, sub-hub, own-page
 * core) gets a distinct `primary_keyword` so no two pages target the same query:
 *
 *   - pillar      → its category head term (head keyword, else silo name)
 *   - sub-hub     → its umbrella framing (the silo name) — distinct from its children, so
 *                   the Backup Power hub lands on "backup power", not a child's "battery backup"
 *   - own-page core → its specific product/service term (head keyword, else name)
 *
 * Collisions (two keywords ≥ the cosine bar) are detected on the §6a embeddings and resolved
 * by case, processing pages by priority (volume desc, name asc) so the outcome is deterministic:
 *
 *   - two sibling own-page cores collide → fold the lower-priority one into the winner (one
 *     keyword, one home — Pass B's fold mechanics);
 *   - a sub-hub umbrella still collides with a child → flag {@see ArrangeFlagType::SubHubKeywordCollision}
 *     (never silently collapse an operator-confirmed demotion);
 *   - anything else ambiguous → flag {@see ArrangeFlagType::KeywordCollision}.
 *
 * Writes `primary_keyword` + the collision score with its own `keyword_source` provenance
 * (separate from the structural `arrangement_source`, so a confirmed demotion doesn't freeze
 * the keyword); a confirmed keyword survives a re-run. A collision *fold* is a structural
 * change, so it gates on the structural `isArrangeable()` instead.
 */
final class KeywordAssigner
{
    public function __construct(
        private readonly float $collisionCosine = 0.90,
    ) {}

    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $spokes = $this->spokes($site);

        // Pages = pillars/sub-hubs + own-page cores; folded spokes are sections, not pages.
        $pages = $spokes
            ->filter(fn (Spoke $s) => $s->is_pillar || $s->granularity === SpokeGranularity::OwnPage)
            ->sortBy([['volume', 'desc'], ['name', 'asc']])
            ->values();

        $assigned = 0;
        $folds = 0;
        $flags = [];
        /** @var list<array{spoke: Spoke, keyword: string}> $taken */
        $taken = [];

        foreach ($pages as $page) {
            $keyword = $this->preferredKeyword($page);
            [$rival, $score] = $this->collision($keyword, $taken, $vectors);

            if ($rival === null) {
                $this->assign($page, $keyword, null);
                $taken[] = ['spoke' => $page, 'keyword' => $keyword];
                $assigned++;

                continue;
            }

            // A sibling own-page core duplicate → fold the lower-priority one into the winner.
            if (! $page->is_pillar && ! $rival->is_pillar) {
                if ($page->isArrangeable()) {
                    $page->update([
                        'granularity' => SpokeGranularity::Folded,
                        'fold_into_id' => $rival->id,
                        'primary_keyword' => null,
                        'keyword_source' => null,
                        'keyword_collision_score' => $score,
                        'arrangement_source' => ArrangementSource::Auto,
                    ]);
                }
                $folds++;

                continue;
            }

            // A sub-hub whose umbrella still collides — flag, never collapse the demotion.
            if ($page->isSubHub()) {
                $flags[] = new ArrangeFlag(
                    ArrangeFlagType::SubHubKeywordCollision,
                    $page->id,
                    "Sub-hub \"{$page->name}\" umbrella \"{$keyword}\" still collides with \"{$rival->name}\" — confirm a distinct term.",
                    [['id' => $rival->id, 'name' => $rival->name, 'score' => round($score, 3)]],
                );
            } else {
                $flags[] = new ArrangeFlag(
                    ArrangeFlagType::KeywordCollision,
                    $page->id,
                    "\"{$page->name}\" targets the same query as \"{$rival->name}\" — confirm a distinct keyword.",
                    [['id' => $rival->id, 'name' => $rival->name, 'score' => round($score, 3)]],
                );
            }

            // Assign the preferred keyword anyway (advisory flag) + record the collision score.
            $this->assign($page, $keyword, $score);
            $taken[] = ['spoke' => $page, 'keyword' => $keyword];
            $assigned++;
        }

        return new ArrangeResult(['keyword' => $assigned, 'keyword_fold' => $folds], $flags);
    }

    private function assign(Spoke $page, string $keyword, ?float $score): void
    {
        if ($page->keyword_source === ArrangementSource::Confirmed) {
            return; // preserve an operator-confirmed keyword (its own provenance, not the structural one)
        }
        $page->update([
            'primary_keyword' => $keyword,
            'keyword_source' => ArrangementSource::Auto,
            'keyword_collision_score' => $score,
        ]);
    }

    private function preferredKeyword(Spoke $page): string
    {
        $head = trim((string) ($page->head_keyword ?? ''));

        if ($page->isSubHub()) {
            return (string) $page->silo; // umbrella framing, distinct from children
        }
        if ($page->is_pillar) {
            return $head !== '' ? $head : (string) $page->silo;
        }

        return $head !== '' ? $head : $page->name;
    }

    /**
     * The highest-scoring already-taken keyword at/above the collision bar, if any.
     *
     * @param  list<array{spoke: Spoke, keyword: string}>  $taken
     * @return array{0: Spoke|null, 1: float}
     */
    private function collision(string $keyword, array $taken, SpokeEmbeddings $vectors): array
    {
        $rival = null;
        $best = -1.0;

        foreach ($taken as $entry) {
            $score = $vectors->textSimilarity($keyword, $entry['keyword']);
            if ($score >= $this->collisionCosine && $score > $best) {
                $best = $score;
                $rival = $entry['spoke'];
            }
        }

        return [$rival, $best];
    }

    /**
     * @return Collection<int, Spoke>
     */
    private function spokes(Site $site): Collection
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();
    }
}
