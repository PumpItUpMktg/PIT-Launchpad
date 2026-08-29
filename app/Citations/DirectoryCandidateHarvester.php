<?php

namespace App\Citations;

use App\Enums\DirectoryScope;
use App\Models\CitationFoundDomain;
use App\Models\Directory;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Surfaces directory CANDIDATES from the citation scan's own captured domains (§ Citations, PR5).
 *
 * The scan persists every result domain in `citation_found_domains`; the ones that matched no catalog entry
 * (`directory_id` null) are unknown directories the platform keeps seeing. A domain that shows up across many
 * tenants/locations is a strong candidate to add to the GLOBAL catalog. This harvests from the module's OWN
 * scan data — never from rank-tracking (which is cache-only and never persisted) — and lets an operator
 * promote a candidate into a real {@see Directory}, back-filling the found rows so the next scan matches it.
 */
final class DirectoryCandidateHarvester
{
    /**
     * Candidate domains ordered by how widely they were seen, excluding anything already in the catalog.
     *
     * @return Collection<int, DirectoryCandidate>
     */
    public function harvest(int $minOccurrences = 1): Collection
    {
        $known = Directory::query()->pluck('domain')
            ->map(fn (string $d): string => $this->normalize($d))
            ->filter()->flip();

        // Aggregate by normalized domain: distinct (site, location) sightings measure breadth, and the first
        // URL seen is a sample for the operator. A plain loop keeps the element types intact.
        $seen = [];      // domain => set of "site:location" keys
        $sample = [];    // domain => ?string
        foreach (CitationFoundDomain::query()->withoutGlobalScope(SiteScope::class)->whereNull('directory_id')->get() as $row) {
            $domain = $this->normalize((string) $row->domain);
            if ($domain === '' || $known->has($domain)) {
                continue;
            }
            $seen[$domain][$row->site_id.':'.$row->location_id] = true;
            if (! isset($sample[$domain]) && $row->found_url !== null) {
                $sample[$domain] = (string) $row->found_url;
            }
        }

        $candidates = [];
        foreach ($seen as $domain => $keys) {
            $occurrences = count($keys);
            if ($occurrences >= $minOccurrences) {
                $candidates[] = new DirectoryCandidate($domain, $occurrences, $sample[$domain] ?? null);
            }
        }
        usort($candidates, fn (DirectoryCandidate $a, DirectoryCandidate $b): int => $b->occurrences <=> $a->occurrences);

        return new Collection($candidates);
    }

    /**
     * Promote a candidate domain into the global catalog and back-fill the found rows that match it, so the
     * next scan attributes them. Extra catalog attributes (name, scope, trade, cost…) come from the operator.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function promote(string $domain, array $attributes = []): Directory
    {
        $normalized = $this->normalize($domain);

        $directory = Directory::query()->create(array_merge([
            'domain' => $normalized,
            'name' => $normalized,
            'scope' => DirectoryScope::National,
            'is_active' => true,
        ], $attributes));

        CitationFoundDomain::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereNull('directory_id')
            ->where('domain', $normalized)
            ->update(['directory_id' => $directory->id]);

        return $directory;
    }

    private function normalize(string $domain): string
    {
        $d = mb_strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d) ?? $d;
        $d = preg_replace('#^www\.#', '', $d) ?? $d;

        return rtrim((string) strtok($d, '/'), '.');
    }
}
