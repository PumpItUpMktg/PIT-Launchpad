<?php

namespace App\KeywordGenerator\Discovery;

use App\Integrations\Keywords\KeywordIdeaProvider;
use App\KeywordGenerator\Pipeline\KeywordPipeline;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * Generates NEW keyword candidates for a site's silos from real keyword-idea data — the piece that was
 * missing from the on-demand "Discover keywords" path. The §5 {@see KeywordPipeline}
 * only ever SCORED/ROUTED keywords already in the database; nothing pulled fresh ideas for a guided
 * silo, so a silo with no seeded keywords stayed "thin" forever no matter how often discovery ran.
 *
 * For each silo carrying routing terms (its rule_set's seed_terms, else include_patterns), this asks the
 * {@see KeywordIdeaProvider} (DataForSEO in prod, mock in tests) for related ideas seeded from the top
 * few terms, then creates deduped {@see Keyword} rows PINNED to that silo (`silo_id` set, so they don't
 * need re-bucketing). The pipeline that runs right after scores them. Geo-modified ideas ("… near me")
 * are dropped — geo lives on location pages, never in a silo's keyword set.
 *
 * Bounded by design (a fixed seeds-per-silo × ideas-per-seed cap) so one run makes a predictable number
 * of provider calls — this is operator-triggered and spends real API budget.
 */
class SiloKeywordGenerator
{
    /** Routing terms taken per silo (the highest-signal seeds), and ideas requested per term. */
    public const SEEDS_PER_SILO = 4;

    public const IDEAS_PER_SEED = 15;

    public function __construct(private readonly KeywordIdeaProvider $ideas) {}

    /**
     * Pull ideas for each silo's seeds and persist new, deduped, silo-pinned Keyword candidates.
     *
     * @return int the number of new keyword rows created
     */
    public function generate(Site $site, int $seedsPerSilo = self::SEEDS_PER_SILO, int $ideasPerSeed = self::IDEAS_PER_SEED): int
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        if ($silos->isEmpty()) {
            return 0;
        }

        // Dedup against everything the site already has (case-insensitive query), and within this run.
        $seen = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->pluck('query')
            ->map(fn ($q): string => $this->norm((string) $q))
            ->flip()
            ->all();

        $created = 0;
        foreach ($silos as $silo) {
            foreach ($this->seedsFor($silo, $seedsPerSilo) as $seed) {
                foreach ($this->ideas->ideas($site, $seed, $ideasPerSeed) as $idea) {
                    $query = $this->norm($idea->keyword);
                    if ($query === '' || $this->isGeo($query) || isset($seen[$query])) {
                        continue;
                    }
                    $seen[$query] = true;

                    Keyword::withoutGlobalScope(SiteScope::class)->forceCreate([
                        'site_id' => $site->id,
                        'silo_id' => $silo->id,
                        'query' => $query,
                        'volume' => $idea->volume,
                        'difficulty' => $idea->difficulty,
                        'source' => 'generated',
                        'status' => 'candidate',
                    ]);
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * A silo's seed terms for idea expansion — the specific spoke heads (seed_terms) preferred, falling
     * back to the broad include_patterns. Capped at $limit; empty when the silo carries no rule_set.
     *
     * @return list<string>
     */
    private function seedsFor(Silo $silo, int $limit): array
    {
        $ruleSet = is_array($silo->rule_set) ? $silo->rule_set : [];
        $terms = is_array($ruleSet['seed_terms'] ?? null) && $ruleSet['seed_terms'] !== []
            ? $ruleSet['seed_terms']
            : (is_array($ruleSet['include_patterns'] ?? null) ? $ruleSet['include_patterns'] : []);

        $clean = [];
        foreach ($terms as $term) {
            $t = $this->norm((string) $term);
            if ($t !== '' && ! in_array($t, $clean, true)) {
                $clean[] = $t;
            }
        }

        return array_slice($clean, 0, max(1, $limit));
    }

    /** Geo-modified ideas ("… near me") don't belong in a silo's keyword set — geo lives on location pages. */
    private function isGeo(string $query): bool
    {
        return str_contains($query, 'near me');
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
