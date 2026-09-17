<?php

namespace App\Console\Commands;

use App\Guided\GrowDashboard;
use App\Guided\LiveBoards;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PagesBoard;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Why is the pages board slow?" — measured, per stage, off the web request.
 *
 * The board renders inside a 60-second gateway, so when it hangs there is nothing to read: no timing, no
 * query count, just a dead tab. This builds exactly what the page builds and reports where the seconds and
 * the queries actually go, with no clock over it. Read-only.
 */
class PagesBoardProbeCommand extends Command
{
    protected $signature = 'launchpad:pages-board-probe
        {site : Site id, brand name, or domain (partial ok)}
        {--location= : The location tab to build (id or name); default the first}';

    protected $description = 'Time the pages board build stage by stage — work lane, live groups, per-location — with query counts. Read-only.';

    public function handle(): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('created_at')->get();
        $needle = trim((string) $this->option('location'));
        $location = $needle === ''
            ? $locations->first()
            : $locations->first(fn (Location $l): bool => (string) $l->id === $needle || str_contains(mb_strtolower((string) $l->name), mb_strtolower($needle)));

        $this->line('<info>Site</info>: '.$site->brand_name.'  ·  locations: '.$locations->count().'  ·  building tab: '.($location !== null ? (string) $location->name : '—'));
        $this->newLine();

        $rows = [];
        // A FRESH instance per stage. These read models memoise per instance, so reusing one makes every
        // stage after the first look free — the probe would flatter exactly the thing it exists to measure.
        $rows[] = $this->stage('work lane (GrowDashboard::sections)', function () use ($site): string {
            $sections = app(GrowDashboard::class)->sections($site);
            $town = collect($sections)->firstWhere('key', 'town');

            return collect($sections)->map(fn (array $s): string => $s['key'].' '.$s['count'])->implode(', ')
                .'  · town rows rendered: '.(int) ($town['count'] ?? 0);
        });

        $rows[] = $this->stage('live groups, ALL locations', fn (): string => 'groups: '.count(app(LiveBoards::class)->locations($site)['groups']));

        if ($location !== null) {
            $rows[] = $this->stage('live groups, one location', function () use ($site, $location): string {
                $groups = app(LiveBoards::class)->locations($site, (string) $location->id)['groups'];
                $built = collect($groups)->firstWhere('location.id', (string) $location->id);

                return 'groups: '.count($groups).' · town cards built: '.count($built['towns'] ?? []);
            });
        }

        $rows[] = $this->stage('whole board (PagesBoard::locations)', function () use ($site, $location): string {
            $data = app(PagesBoard::class)->locations($site, $location !== null ? (string) $location->id : null);

            return 'work rows: '.count($data['work']).' · groups: '.count($data['live']['groups']);
        });

        $this->table(['Stage', 'Seconds', 'Queries', 'What it built'], $rows);
        $this->comment('The page renders the whole board inside a 60s gateway. Any stage near that is the one to fix.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): string  $fn
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function stage(string $label, callable $fn): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $started = microtime(true);
        $detail = $fn();
        $seconds = microtime(true) - $started;

        return [$label, number_format($seconds, 2), $queries, $detail];
    }

    private function site(): ?Site
    {
        $needle = (string) $this->argument('site');
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
