<?php

namespace App\Audit;

use App\Models\Site;
use Throwable;

/**
 * Runs a set of {@see AuditCheck}s across a set of tenants and assembles the {@see AuditReport}. A
 * check that throws is caught and recorded as a single tenant-level finding so one broken check can't
 * abort a whole-portfolio run.
 */
final class AuditRunner
{
    /**
     * @param  list<Site>  $sites
     * @param  list<AuditCheck>  $checks
     */
    public function run(array $sites, array $checks): AuditReport
    {
        $matrix = [];
        foreach ($sites as $site) {
            foreach ($checks as $check) {
                try {
                    $matrix[$site->id][$check->id()] = $check->run($site);
                } catch (Throwable $e) {
                    $matrix[$site->id][$check->id()] = [
                        new Finding($site->id, (string) $site->brand_name, null, 'check errored: '.$e->getMessage()),
                    ];
                }
            }
        }

        return new AuditReport($checks, $sites, $matrix);
    }
}
