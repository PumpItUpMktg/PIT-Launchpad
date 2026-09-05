<?php

namespace App\Console\Commands;

use App\Locations\Diagnostics\LocationAudit;
use Illuminate\Console\Command;

/**
 * READ-ONLY report (location-integrity relay, item 4): every live town page whose parent location does
 * NOT serve the town's county, across ALL tenants — each with the correct parent (the location that
 * actually serves that county) and the cost of moving it (current URL → proposed URL, index status,
 * inbound links). Plus a same-name-across-states section (Montgomery / Newark / Washington / Franklin /
 * Springfield / Union exist in both NJ and PA) — the in-state collisions the cross-state Trooper/
 * Montgomery case wouldn't surface on its own. A plan, not a list. Never writes.
 *
 * Counts are LIVE-ONLY: soft-deleted content and NAP-merged locations are excluded.
 */
class ReportTownAssignmentCommand extends Command
{
    protected $signature = 'launchpad:report-town-assignment';

    protected $description = 'Report (read-only) town pages whose parent location does not serve their county, with the correct parent and the cost of the fix.';

    public function handle(LocationAudit $audit): int
    {
        $this->info('Counts are LIVE-ONLY (soft-deleted content + NAP-merged locations excluded). Read-only — nothing written.');

        $drift = $audit->townAssignmentDrift();
        $this->newLine();
        $this->line('── Mis-assigned town pages (parent does not serve the town\'s county) ──');

        if ($drift === []) {
            $this->info('None found — every live town page\'s parent serves its county.');
        } else {
            foreach ($drift as $p) {
                $this->newLine();
                $this->warn("● {$p['town']}  [county {$p['town_county_geoid']}]  (site {$p['site_id']})");
                $this->line("    current parent: {$p['current_parent']}  serves: ".(implode(', ', $p['current_parent_counties']) ?: '—'));
                $this->line('    correct parent: '.($p['correct_parent'] ?? '(no live location serves this county)'));
                $this->line('    now:      '.$p['current_url'].'  '.$this->costTag($p));
                $this->line('    move to:  '.($p['proposed_url'] ?? '(correct parent has no hub landing to nest under)'));
            }
            $this->newLine();
            $this->warn(count($drift).' mis-assigned page(s). Each move needs a 301 + a resubmission — see the cost tags.');
        }

        $collisions = $audit->sameNameAcrossStates();
        $this->newLine();
        $this->line('── Same-name towns across states (silent collision risk) ──');
        if ($collisions === []) {
            $this->info('None found.');
        } else {
            foreach ($collisions as $c) {
                $this->line("    {$c['name']}: ".implode(' / ', $c['states']).'  [counties '.implode(', ', $c['county_geoids']).']');
            }
            $this->warn(count($collisions).' town name(s) exist in more than one state — a name-only match can cross a state line.');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $p */
    private function costTag(array $p): string
    {
        $idx = $p['indexed']['indexed'] ? 'INDEXED' : ($p['indexed']['coverage_state'] ?? 'not indexed');

        return "[{$idx} · {$p['inbound_links']} inbound link(s)]";
    }
}
