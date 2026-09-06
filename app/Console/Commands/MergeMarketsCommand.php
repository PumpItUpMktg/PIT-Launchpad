<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\MarketMerger;
use Illuminate\Console\Command;

/**
 * Merge DUPLICATE markets (two rows on the same Census `geo_id`) into the clean-named survivor and delete the
 * duplicate — reassigning every dependent first (keywords, content, snapshots, geo prompts, service/proof/
 * media pivots) and cleaning the place's one CoverageArea name so the next build can't re-mint the duplicate.
 *
 * REPORT-ONLY by default: prints the per-group plan and writes NOTHING. Pass --execute to apply. An AMBIGUOUS
 * group (no single clean survivor) is reported and left for a human — never auto-merged. Live-only, all
 * tenants (or one via --site). geo_id is the authoritative "same market" signal — the name check can't see it.
 */
class MergeMarketsCommand extends Command
{
    protected $signature = 'launchpad:merge-markets
        {--site= : Limit to one site id or brand name}
        {--execute : Apply the merges (default: report-only — writes nothing)}';

    protected $description = 'Merge duplicate markets (same geo_id) into the clean twin and delete the duplicate (report-only by default; --execute to apply).';

    public function handle(MarketMerger $merger): int
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

        $execute = (bool) $this->option('execute');
        $this->info($execute
            ? 'EXECUTE · live-only · merging duplicate markets (same geo_id) into the clean twin.'
            : 'Read-only · live-only · duplicate-market MERGE plan. Nothing is changed (pass --execute to apply).');

        $grandMerges = 0;
        $grandAmbiguous = 0;
        foreach ($sites as $site) {
            $plan = $merger->plan($site);
            if ($plan === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($plan as $r) {
                if ($r['ambiguous']) {
                    $grandAmbiguous++;
                    $this->line("  · <fg=yellow>AMBIGUOUS</> geo_id {$r['geo_id']} — no single clean survivor among [".implode(' | ', $r['names']).']; decide by hand.');

                    continue;
                }

                $grandMerges++;
                $d = $r['dependents'];
                $deps = "kw {$d['keywords']}, pages {$d['content']}, snaps {$d['snapshots']}, geo {$d['geo_prompts']}, svc {$d['services']}, proof {$d['proof']}, media {$d['media']}";
                $area = ($r['area_id'] !== null && $r['area_dirty']) ? ' + clean its CoverageArea name' : '';
                $this->line("  · merge <comment>\"{$r['loser_name']}\"</comment> → <info>\"{$r['winner_name']}\"</info> (geo_id {$r['geo_id']}); reassign [{$deps}]{$area}, then delete the duplicate.");
            }

            if ($execute) {
                $merger->apply($site);
            }
        }

        $this->newLine();
        if ($grandMerges === 0 && $grandAmbiguous === 0) {
            $this->info('No duplicate markets found (no geo_id carried by two rows).');

            return self::SUCCESS;
        }
        if ($grandAmbiguous > 0) {
            $this->warn("{$grandAmbiguous} ambiguous group(s) left for a human — no single clean survivor.");
        }

        if (! $execute) {
            $this->info("{$grandMerges} duplicate market(s) would be merged + deleted. Re-run with --execute to apply (nothing was changed). The reassigned pages are duplicates too — run launchpad:report-duplicate-town-pages next.");

            return self::SUCCESS;
        }

        // Write-verification: re-read and confirm no unambiguous duplicate remains.
        $remaining = 0;
        foreach ($sites as $site) {
            $remaining += count(array_filter($merger->plan($site->fresh() ?? $site), fn (array $r): bool => ! $r['ambiguous']));
        }
        $this->info("Merged {$grandMerges} duplicate market(s). Remaining mergeable duplicates after re-read: {$remaining}.");

        return self::SUCCESS;
    }
}
