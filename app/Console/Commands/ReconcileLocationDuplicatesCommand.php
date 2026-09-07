<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\LocationDuplicateReconciler;
use Illuminate\Console\Command;

/**
 * Standing READ-ONLY reconcile for the landing/town duplicate class — the safety net after the selection
 * guard and the one-time cleanup, so the class can't silently reaccumulate. See
 * {@see LocationDuplicateReconciler}. It reports two things and writes NOTHING:
 *
 *  - live duplicate location pages (landing↔town + town↔town) still published — resolve with
 *    launchpad:resolve-live-duplicates;
 *  - a `page_selected` town that IS a physical location's own city (the source smell) — deselect it.
 *
 * A clean run is the expected steady state; any finding means a guard regressed or an operator
 * mis-selected. All tenants, or one via --site.
 */
class ReconcileLocationDuplicatesCommand extends Command
{
    protected $signature = 'launchpad:reconcile-location-duplicates {--site= : Limit to one site id or brand name}';

    protected $description = 'Report (read-only) live duplicate location pages + page_selected self-city towns, so the duplicate class can\'t silently reaccumulate.';

    public function handle(LocationDuplicateReconciler $reconciler): int
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

        $this->info('Read-only · location-duplicate reconcile (live duplicate pages + self-city selections).');

        $grandDup = 0;
        $grandSelfCity = 0;
        foreach ($sites as $site) {
            $report = $reconciler->report($site);
            $live = array_values(array_filter($report['live_duplicates'], fn (array $g): bool => ! $g['ambiguous']));
            $ambiguous = array_values(array_filter($report['live_duplicates'], fn (array $g): bool => $g['ambiguous']));
            $selfCities = $report['selected_self_cities'];

            if ($live === [] && $ambiguous === [] && $selfCities === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");

            foreach ($live as $g) {
                $grandDup++;
                $keeper = $g['keeper'];
                $losers = implode(', ', array_map(fn (array $l): string => $l['from'], $g['losers']));
                $this->line("  · <fg=red>LIVE DUPLICATE</> {$g['town']} — keep [{$keeper['role']}] {$keeper['path']}; redundant: {$losers}");
            }
            foreach ($ambiguous as $g) {
                $this->line("  · <fg=yellow>AMBIGUOUS</> {$g['town']} — no clear keeper among [".implode(' | ', $g['names']).']');
            }
            foreach ($selfCities as $sc) {
                $grandSelfCity++;
                $state = $sc['state'] !== null && $sc['state'] !== '' ? ', '.$sc['state'] : '';
                $this->line("  · <fg=yellow>SELF-CITY SELECTED</> \"{$sc['name']}{$state}\" is page_selected but is a physical location's own city — deselect it (its landing already covers it).");
            }
        }

        $this->newLine();
        if ($grandDup === 0 && $grandSelfCity === 0) {
            $this->info('Clean — no live duplicate location pages, no self-city selections.');

            return self::SUCCESS;
        }

        if ($grandDup > 0) {
            $this->warn("{$grandDup} live duplicate group(s) — run launchpad:resolve-live-duplicates.");
        }
        if ($grandSelfCity > 0) {
            $this->warn("{$grandSelfCity} self-city town(s) still page_selected — deselect them (the guard prevents new ones; these predate it or were set by hand).");
        }

        return self::SUCCESS;
    }
}
