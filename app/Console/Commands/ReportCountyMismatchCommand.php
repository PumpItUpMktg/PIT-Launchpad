<?php

namespace App\Console\Commands;

use App\Locations\Diagnostics\LocationAudit;
use Illuminate\Console\Command;

/**
 * READ-ONLY report (location-integrity relay, item 2): locations that serve counties NOT including their
 * own (home) county — the Spring City shape (home 42029 Chester PA; served 42077/42095 Lehigh/Northampton).
 * For each such location it lists the published town pages sitting under it and, per page, where it would
 * move (current URL → proposed URL under the location that actually serves the town's county) plus the
 * cost of moving it (index status, inbound links). A plan, not a list. Never writes.
 *
 * Counts are LIVE-ONLY: soft-deleted content and NAP-merged locations are excluded.
 */
class ReportCountyMismatchCommand extends Command
{
    protected $signature = 'launchpad:report-county-mismatch';

    protected $description = 'Report (read-only) locations whose served counties exclude their home county, and the published town pages that would move.';

    public function handle(LocationAudit $audit): int
    {
        $rows = $audit->countyMismatches();

        $this->info('Counts are LIVE-ONLY (soft-deleted content + NAP-merged locations excluded). Read-only — nothing written.');

        if ($rows === []) {
            $this->info('No county-mismatched locations found.');

            return self::SUCCESS;
        }

        foreach ($rows as $loc) {
            $this->newLine();
            $this->warn("● {$loc['location']}  (site {$loc['site_id']}, location {$loc['location_id']})");
            $this->line("  home county: {$loc['home_county_geoid']}  ·  served: ".(implode(', ', $loc['served_county_geoids']) ?: '—'));
            $this->line('  '.count($loc['pages']).' published town page(s) under it:');

            foreach ($loc['pages'] as $p) {
                $this->line("    - {$p['town']}  [county {$p['town_county_geoid']}]");
                $this->line('        now:      '.$p['current_url'].'  '.$this->costTag($p));
                $this->line('        '.$this->fixLine($p));
            }
        }

        $this->newLine();
        $this->warn(count($rows).' location(s) with a home-county mismatch. Each page move needs a 301 + a resubmission — see the cost tags.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $p */
    private function costTag(array $p): string
    {
        $idx = $p['indexed']['indexed'] ? 'INDEXED' : ($p['indexed']['coverage_state'] ?? 'not indexed');

        return "[{$idx} · {$p['inbound_links']} inbound link(s)]";
    }

    /** @param  array<string, mixed>  $p */
    private function fixLine(array $p): string
    {
        return match ($p['parenting']) {
            'correct' => '✓ already correctly parented (its parent serves this county)',
            'move' => "move to:  {$p['proposed_url']}   → parent: {$p['correct_parent']}",
            'no_server' => 'no live location serves this county — nothing to re-parent to',
            default => 'town county unresolved (no matching coverage area)',
        };
    }
}
