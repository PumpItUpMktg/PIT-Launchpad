<?php

namespace App\KeywordGenerator\Corpus;

use App\Integrations\Keywords\KeywordIdeaProvider;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\Keyword;
use App\Models\KeywordCorpus;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\SiloCreator\GeoNeutralValidator;

/**
 * Part 1 of keyword-first structure generation — accumulate a tenant keyword corpus BEFORE any
 * structure exists. Seeds (trade + service names + existing keyword rows) expand through the
 * {@see KeywordIdeaProvider} (DataForSEO, geo-localized volumes), then normalize + dedup to a canonical
 * form, drop geo-modified terms (the derived structure stays geo-neutral), attach intent, and cap to a
 * breadth guardrail. Re-runnable: a second run refreshes metrics and adds new terms but NEVER wipes an
 * operator's keep/dismiss disposition or a term's cluster assignment.
 */
final class CorpusAccumulator
{
    public function __construct(
        private readonly KeywordIdeaProvider $ideas,
        private readonly KeywordNormalizer $normalizer,
        private readonly IntentClassifier $intent,
        private readonly GeoNeutralValidator $geo,
    ) {}

    public function accumulate(Site $site): CorpusResult
    {
        $perSeedCap = (int) config('launchpad.keyword_first.per_seed_cap', 40);
        $totalCap = (int) config('launchpad.keyword_first.total_cap', 600);

        $seeds = $this->seeds($site);

        /** @var array<string, array{term: string, canonical: string, volume: int, competition: float|null, difficulty: int|null, source: string, seed_term: string|null}> $acc */
        $acc = [];
        $geoFiltered = 0;

        $add = function (string $term, int $volume, ?float $competition, ?int $difficulty, string $source, ?string $seedTerm) use (&$acc, &$geoFiltered, $site): void {
            $term = trim($term);
            if ($term === '') {
                return;
            }
            if ($this->geo->hasGeoTerm($term, $site->id)) {
                $geoFiltered++;

                return;
            }
            $canonical = $this->normalizer->canonical($term);
            if ($canonical === '') {
                return;
            }

            if (! isset($acc[$canonical])) {
                $acc[$canonical] = [
                    'term' => $term,
                    'canonical' => $canonical,
                    'volume' => $volume,
                    'competition' => $competition,
                    'difficulty' => $difficulty,
                    'source' => $source,
                    'seed_term' => $seedTerm,
                ];

                return;
            }
            // Highest-volume variant wins the display term + metrics; a seed origin is sticky.
            if ($volume > $acc[$canonical]['volume']) {
                $acc[$canonical]['term'] = $term;
                $acc[$canonical]['volume'] = $volume;
                $acc[$canonical]['competition'] = $competition;
                $acc[$canonical]['difficulty'] = $difficulty;
            }
            if ($source === 'seed') {
                $acc[$canonical]['source'] = 'seed';
            }
            $acc[$canonical]['seed_term'] ??= $seedTerm;
        };

        foreach ($seeds as $seed) {
            $add($seed, 0, null, null, 'seed', null);
            foreach ($this->ideas->ideas($site, $seed, $perSeedCap) as $idea) {
                $add($idea->keyword, $idea->volume, $idea->competition, $idea->difficulty, 'expansion', $seed);
            }
        }

        $rows = array_values($acc);
        usort($rows, fn (array $a, array $b): int => $b['volume'] <=> $a['volume']);
        $capped = count($rows) > $totalCap;
        $rows = array_slice($rows, 0, $totalCap);

        $added = 0;
        $refreshed = 0;
        foreach ($rows as $row) {
            $intent = $this->intent->classify($row['term'])->value;

            $existing = KeywordCorpus::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('canonical', $row['canonical'])
                ->first();

            if ($existing === null) {
                (new KeywordCorpus)->forceFill([
                    'site_id' => $site->id,
                    'term' => $row['term'],
                    'canonical' => $row['canonical'],
                    'volume' => $row['volume'],
                    'competition' => $row['competition'],
                    'difficulty' => $row['difficulty'],
                    'intent' => $intent,
                    'source' => $row['source'],
                    'seed_term' => $row['seed_term'],
                    'last_refreshed_at' => now(),
                ])->save();
                $added++;

                continue;
            }

            // Refresh metrics only — disposition + cluster_id are the operator's / clustering's, untouched.
            $existing->forceFill([
                'term' => $row['term'],
                'volume' => $row['volume'],
                'competition' => $row['competition'],
                'difficulty' => $row['difficulty'],
                'intent' => $intent,
                'source' => $row['source'] === 'seed' ? 'seed' : $existing->source,
                'seed_term' => $existing->seed_term ?? $row['seed_term'],
                'last_refreshed_at' => now(),
            ])->save();
            $refreshed++;
        }

        $total = KeywordCorpus::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();

        return new CorpusResult($added, $refreshed, $total, count($seeds), $geoFiltered, $capped);
    }

    /**
     * The seed set: trade (the structure seed) + every service name + every existing keyword row.
     * Deduped by canonical so a service and a keyword that normalize the same aren't expanded twice.
     *
     * @return list<string>
     */
    private function seeds(Site $site): array
    {
        $raw = [];

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($blueprint !== null) {
            $trade = trim((string) ($blueprint->trade ?? ($blueprint->seed['trade'] ?? '')));
            if ($trade !== '') {
                $raw[] = $trade;
            }
        }

        foreach (Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name') as $name) {
            $raw[] = trim((string) $name);
        }
        foreach (Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('query') as $query) {
            $raw[] = trim((string) $query);
        }

        $seen = [];
        $out = [];
        foreach ($raw as $seed) {
            if ($seed === '') {
                continue;
            }
            $canonical = $this->normalizer->canonical($seed);
            if ($canonical === '' || isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;
            $out[] = $seed;
        }

        return $out;
    }
}
