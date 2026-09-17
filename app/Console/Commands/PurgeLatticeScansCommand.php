<?php

namespace App\Console\Commands;

use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete the retired 7×7 lattice scans (`mode = grid`).
 *
 * The Geo Grid board reads town-centre (coverage) scans now — a lattice cell is a point on a square that
 * answers for no town in particular, so it cannot be redrawn as a town and nothing displays these rows any
 * more. They are dead weight carrying 49 points apiece.
 *
 * Report-first and destructive: the default run counts what would go and writes nothing; `--execute` is the
 * confirmation (there is no prompt — a hosted Commands panel cannot answer one). Points go with their scan
 * through the existing cascade. Coverage scans are never touched.
 */
class PurgeLatticeScansCommand extends Command
{
    protected $signature = 'launchpad:purge-lattice-scans
        {site? : Site id, brand name, or domain (partial ok); omit for every site}
        {--execute : Delete them. Without this the command only reports.}';

    protected $description = 'Delete the retired 7×7 lattice geo-grid scans (mode=grid) and their points — report by default, --execute to delete. Coverage scans are untouched.';

    public function handle(): int
    {
        $site = null;
        $needle = trim((string) $this->argument('site'));
        if ($needle !== '') {
            $site = $this->site($needle);
            if ($site === null) {
                return self::FAILURE;
            }
        }

        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)->where('mode', 'grid')
            ->when($site !== null, fn ($q) => $q->where('site_id', $site->id));

        $ids = (clone $scans)->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('No lattice scans'.($site !== null ? ' for this site' : '').' — nothing to purge.');

            return self::SUCCESS;
        }

        $points = GeoGridPoint::withoutGlobalScope(SiteScope::class)->whereIn('scan_id', $ids)->count();
        $oldest = (clone $scans)->min('scanned_at');
        $newest = (clone $scans)->max('scanned_at');

        $rows = (clone $scans)->toBase()
            ->selectRaw('site_id, count(*) as scans, min(scanned_at) as oldest, max(scanned_at) as newest')
            ->groupBy('site_id')->get();
        $brands = Site::withoutGlobalScopes()->whereIn('id', $rows->pluck('site_id'))->pluck('brand_name', 'id');

        $this->table(['Site', 'Lattice scans', 'Oldest', 'Newest'], $rows->map(fn (object $r): array => [
            (string) ($brands[(string) $r->site_id] ?? $r->site_id),
            (int) $r->scans,
            (string) ($r->oldest ?? '—'),
            (string) ($r->newest ?? '—'),
        ])->all());

        $this->line(sprintf('<info>%d lattice scan(s)</info> carrying <info>%d point(s)</info>, %s → %s.',
            $ids->count(), $points, (string) ($oldest ?? '—'), (string) ($newest ?? '—')));

        if (! (bool) $this->option('execute')) {
            $this->comment('Read-only. Re-run with --execute to delete them. Coverage (town-centre) scans are never touched.');

            return self::SUCCESS;
        }

        // Points cascade with their scan; delete in chunks so one statement never carries every id.
        $deleted = 0;
        foreach ($ids->chunk(500) as $chunk) {
            $deleted += DB::transaction(fn (): int => GeoGridScan::withoutGlobalScope(SiteScope::class)->whereIn('id', $chunk->all())->delete());
        }

        $this->line("<info>Deleted</info> {$deleted} lattice scan(s) and their points.");

        return self::SUCCESS;
    }

    private function site(string $needle): ?Site
    {
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        /** @var Site $site */
        $site = $matches->first();

        return $site;
    }
}
