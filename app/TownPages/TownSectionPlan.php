<?php

namespace App\TownPages;

use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;

/**
 * The per-town decision behind every push: of the priority keywords a town's size carries, which to DRAFT
 * (the town is off page 1 for them, or never scanned) and which to KEEP as they are (the town already
 * ranks page 1 — the operator's fear is a rewrite that loses a ranking the page already holds). A kept
 * keyword's existing section stays untouched; a kept keyword with no section gets none. `expected` is what
 * the writer keeps on the page: the draft set plus the kept sections it already has.
 */
final class TownSectionPlan
{
    public function __construct(
        private readonly PriorityKeywords $priority,
        private readonly PrioritySections $sections,
        private readonly TownStanding $standing,
    ) {}

    /**
     * @return array{tier: list<Keyword>, draft: list<Keyword>, keep: list<array{keyword: Keyword, rank: int}>, expected: list<Keyword>, standing: array<string, array{rank: int|null, measured_at: string|null, scanned: bool}>, stored: list<string>, need: list<string>, extra: list<string>}
     */
    public function for(Site $site, Content $page, int $population): array
    {
        $tier = $this->priority->forPopulation($site, $population);
        $standing = $tier === [] ? [] : $this->standing->forTown($site, (string) $page->geo_id, $tier);

        return $this->decide($page, $tier, $standing);
    }

    /**
     * The same for many pages of one site (one standing read for all), keyed by content id.
     *
     * @param  list<array{page: Content, population: int}>  $pages
     * @return array<string, array{tier: list<Keyword>, draft: list<Keyword>, keep: list<array{keyword: Keyword, rank: int}>, expected: list<Keyword>, standing: array<string, array{rank: int|null, measured_at: string|null, scanned: bool}>, stored: list<string>, need: list<string>, extra: list<string>}>
     */
    public function forMany(Site $site, array $pages): array
    {
        $all = $this->priority->for($site);
        $standing = $all === [] ? [] : $this->standing->forTowns($site, array_map(fn (array $p): string => (string) $p['page']->geo_id, $pages), $all);
        $out = [];
        foreach ($pages as $entry) {
            $tier = $this->priority->forPopulation($site, $entry['population']);
            $tierIds = array_fill_keys(PrioritySections::ids($tier), true);
            $townStanding = array_intersect_key($standing[(string) $entry['page']->geo_id] ?? [], $tierIds);
            $out[(string) $entry['page']->id] = $this->decide($entry['page'], $tier, $townStanding);
        }

        return $out;
    }

    /**
     * @param  list<Keyword>  $tier
     * @param  array<string, array{rank: int|null, measured_at: string|null, scanned: bool}>  $standing
     * @return array{tier: list<Keyword>, draft: list<Keyword>, keep: list<array{keyword: Keyword, rank: int}>, expected: list<Keyword>, standing: array<string, array{rank: int|null, measured_at: string|null, scanned: bool}>, stored: list<string>, need: list<string>, extra: list<string>}
     */
    private function decide(Content $page, array $tier, array $standing): array
    {
        $stored = $this->sections->storedKeywordIds($page);
        $storedSet = array_fill_keys($stored, true);
        $draft = [];
        $keep = [];
        $expected = [];
        foreach ($tier as $keyword) {
            $id = (string) $keyword->id;
            $rank = $standing[$id]['rank'] ?? null;
            if (TownStanding::ranking($rank)) {
                $keep[] = ['keyword' => $keyword, 'rank' => (int) $rank];
                if (isset($storedSet[$id])) {
                    $expected[] = $keyword;
                }

                continue;
            }
            $draft[] = $keyword;
            $expected[] = $keyword;
        }
        $expectedIds = array_fill_keys(PrioritySections::ids($expected), true);
        $need = array_values(array_filter(PrioritySections::ids($draft), fn (string $id): bool => ! isset($storedSet[$id])));
        $extra = array_values(array_filter($stored, fn (string $id): bool => ! isset($expectedIds[$id])));

        return [
            'tier' => $tier,
            'draft' => $draft,
            'keep' => $keep,
            'expected' => $expected,
            'standing' => $standing,
            'stored' => $stored,
            'need' => $need,
            'extra' => $extra,
        ];
    }
}
