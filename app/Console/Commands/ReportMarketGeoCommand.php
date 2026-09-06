<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\MarketGeoAudit;
use Illuminate\Console\Command;

/**
 * Report (read-only): MARKET-GEO AUDIT — surfaces suspect Markets across three lenses (geo validity,
 * dependents, Location correspondence) so a corrupt/fabricated market's disposition is decided PER ROW,
 * never as a blanket repair. It proposes; it never changes anything.
 *
 * Repair a real place with a bad coordinate; DELETE a fabricated market (re-geocoding one only buys valid
 * coordinates to spend credits proving you don't rank where you don't operate). Dependents + the Location
 * heuristic separate the two — the operator makes the call.
 *
 * READ-ONLY, live-only, all tenants (or one via --site). --all also lists clean markets.
 */
class ReportMarketGeoCommand extends Command
{
    protected $signature = 'launchpad:report-market-geo
        {--site= : Limit to one site id or brand name}
        {--all : Include clean (geo-valid + Location-matched) markets too}';

    protected $description = 'Report (read-only) suspect markets — geo validity, dependents, and Location correspondence — per row.';

    public function handle(MarketGeoAudit $audit): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $includeClean = (bool) $this->option('all');
        $this->info('Read-only · live-only · market-geo audit (geo · dependents · Location heuristic). Nothing is changed.');

        $grandSuspect = 0;
        foreach ($sites as $site) {
            $rows = $audit->suspects($site, $includeClean);
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — ".count($rows).' market(s) to review');

            foreach ($rows as $r) {
                $grandSuspect++;
                $geo = match ($r['geo']) {
                    'valid' => 'geo OK',
                    'out_of_area' => "geo OUT-OF-AREA ({$r['lat']},{$r['lng']})",
                    default => 'geo MISSING',
                };
                $id = $r['geo_id'] !== null ? "geo_id {$r['geo_id']}" : 'geo_id NONE';
                $loc = $r['location_match'] ? 'Location: match (heuristic)' : 'Location: NO match (heuristic)';
                $art = $r['name_artifact'] ? ' · name: "N," artifact' : '';
                $d = $r['dependents'];
                $deps = "deps {$r['total_dependents']} [kw {$d['keywords']}, pages {$d['content']}, snaps {$d['snapshots']}, svc {$d['services']}, proof {$d['proof']}, media {$d['media']}]";

                $this->newLine();
                $this->line("  · <comment>\"{$r['name']}\"</comment> / {$r['region']} ({$r['tier']})");
                $this->line("      {$geo} · {$id} · {$loc}{$art} · {$deps}");
                $this->line("      → {$r['advisory']}");
            }
        }

        $this->newLine();
        $this->info($grandSuspect === 0
            ? 'No suspect markets — every market is geo-valid and Location-matched.'
            : "{$grandSuspect} suspect market(s) across all tenants. Decide repair vs delete per row (nothing was changed).");

        return self::SUCCESS;
    }
}
