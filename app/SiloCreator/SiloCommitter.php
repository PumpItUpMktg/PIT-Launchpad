<?php

namespace App\SiloCreator;

use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persists an accepted, reviewed proposal set as a coherent silo tree: silos
 * with hierarchy, service mappings, rule_sets, and pillars. Enforces the
 * geo-neutral rule before writing anything. wp_category_id is intentionally
 * left unset — the §2 publish pipeline fills it.
 */
class SiloCommitter
{
    public function __construct(
        private readonly GeoNeutralValidator $geo,
        private readonly PillarFactory $pillars,
    ) {}

    /**
     * @return Collection<int, Silo>
     */
    public function commit(Site $site, SiloProposalSet $proposals): Collection
    {
        foreach ($proposals as $proposal) {
            $violations = $this->geo->violations($proposal->name, $proposal->ruleSet, $site->id);
            if ($violations !== []) {
                throw new GeoNeutralViolationException($proposal->name, $violations);
            }
        }

        return DB::transaction(function () use ($site, $proposals): Collection {
            /** @var array<string, Silo> $byName */
            $byName = [];

            // Pass 1: create silos + attach services.
            foreach ($proposals as $proposal) {
                $silo = Silo::create([
                    'site_id' => $site->id,
                    'name' => $proposal->name,
                    'type' => $proposal->type,
                    'rule_set' => $proposal->ruleSet->toArray(),
                    'status' => 'active',
                ]);

                if ($proposal->serviceIds !== []) {
                    $silo->services()->attach($proposal->serviceIds);
                }

                $byName[$proposal->name] = $silo;
            }

            // Pass 2: wire hierarchy now that every silo exists.
            foreach ($proposals as $proposal) {
                if ($proposal->parentName !== null && isset($byName[$proposal->parentName])) {
                    $byName[$proposal->name]->update([
                        'parent_silo_id' => $byName[$proposal->parentName]->id,
                    ]);
                }
            }

            // Pass 3: assign pillars.
            foreach ($proposals as $proposal) {
                $this->pillars->ensurePillar($byName[$proposal->name], $proposal);
            }

            return collect(array_values($byName));
        });
    }
}
