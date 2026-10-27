<?php

namespace App\Console\Commands;

use App\Jobs\ResolveTownSources;
use App\Locations\Sources\TownSourceResolver;
use App\Models\Site;
use Illuminate\Console\Command;

/** launchpad:resolve-town-sources — the official local sources (municipality, county, FEMA flood map) a town page cites. */
class ResolveTownSourcesCommand extends Command
{
    protected $signature = 'launchpad:resolve-town-sources
        {--site= : The site ULID}
        {--limit=60 : How many towns to resolve this run (max 500)}
        {--force : Resolve again, including towns already resolved}
        {--revalidate : Re-check every resolved town\'s picks against the current rules (no search); re-search only the towns that lose a pick}
        {--queue : Run on the worker in chunks instead of here}';

    protected $description = 'Resolve the official local sources a town page cites: the municipality\'s website, the county\'s, the FEMA flood-map search (one search each, government domains only, checked live).';

    public function handle(TownSourceResolver $resolver): int
    {
        $site = Site::withoutGlobalScopes()->find((string) $this->option('site'));
        if ($site === null) {
            $this->error('Pass --site=<ulid>.');

            return self::FAILURE;
        }
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        if ((bool) $this->option('revalidate')) {
            if ((bool) $this->option('queue')) {
                ResolveTownSources::dispatch((string) $site->id, $limit, false, true);
                $this->line("<info>{$site->brand_name}</info> — re-validation queued on the worker.");

                return self::SUCCESS;
            }
            $checked = $redone = 0;
            foreach (ResolveTownSources::resolved((string) $site->id) as $area) {
                $checked++;
                if ($resolver->revalidate($area)) {
                    $redone++;
                    $sources = $resolver->resolve($area->fresh() ?? $area);
                    $this->line(sprintf('  ↻ %-28s %s', $area->name.', '.$area->state, implode(' · ', array_map(fn (array $s): string => $s['key'].': '.$s['url'], $sources))));
                }
            }
            $this->line(sprintf('Re-validated %d town(s); %d lost a pick under the current rules and were re-resolved.', $checked, $redone));

            return self::SUCCESS;
        }
        $pending = ResolveTownSources::pending((string) $site->id, $limit, $force);
        if ($pending === []) {
            $this->line("<info>{$site->brand_name}</info> — every town is resolved.");

            return self::SUCCESS;
        }
        if ((bool) $this->option('queue')) {
            ResolveTownSources::dispatch((string) $site->id, $limit, $force);
            $this->line(sprintf('<info>%s</info> — %d town(s) queued on the worker in chunks of %d.', $site->brand_name, count($pending), ResolveTownSources::CHUNK));

            return self::SUCCESS;
        }
        $this->line(sprintf('<info>%s</info> — resolving %d town(s):', $site->brand_name, count($pending)));
        $with = 0;
        foreach ($pending as $area) {
            $sources = $resolver->resolve($area);
            $keys = array_column($sources, 'key');
            $with += in_array(TownSourceResolver::MUNICIPALITY, $keys, true) ? 1 : 0;
            $this->line(sprintf('  %s %-28s %s', in_array(TownSourceResolver::MUNICIPALITY, $keys, true) ? '•' : '◦', $area->name.', '.$area->state, implode(' · ', array_map(fn (array $s): string => $s['key'].': '.$s['url'], $sources))));
        }
        $this->line(sprintf('Done: %d of %d town(s) have an official municipal site; the rest cite the county and the flood map only.', $with, count($pending)));

        return self::SUCCESS;
    }
}
