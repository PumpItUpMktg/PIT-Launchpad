<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Social\Rules\MomentumRule;
use App\Social\Rules\WeatherRule;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The social re-share rules, daily after the Search Console sync: upward movement → a suggestion on the
 * board; heavy rain → an automatic share drawn from the rain pool. Report-first; --execute writes.
 */
class SocialRulesCommand extends Command
{
    protected $signature = 'launchpad:social-rules
        {site? : A site (id, brand, or domain)}
        {--all : Every site}
        {--execute : Write the suggestions and send the rain shares}';

    protected $description = 'Run the social re-share rules (upward movement → board; heavy rain → auto share)';

    public function handle(MomentumRule $momentum, WeatherRule $weather): int
    {
        if (! (bool) config('launchpad.social.enabled', true)) {
            $this->warn('Social shares are off (launchpad.social.enabled).');

            return self::SUCCESS;
        }
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }
        if ($sites->isEmpty()) {
            $this->warn('No site — pass a site, or --all.');

            return self::SUCCESS;
        }
        $execute = (bool) $this->option('execute');
        foreach ($sites as $site) {
            $this->newLine();
            $this->line(sprintf('<info>%s</info>', $site->brand_name));
            $hits = $momentum->run($site, $execute);
            $this->line(sprintf('  Upward movement: %d post(s)%s', count($hits), $execute ? sprintf(', %d suggested', count(array_filter($hits, fn (array $h): bool => $h['suggested']))) : ''));
            foreach ($hits as $h) {
                $this->line(sprintf('    · %s — %s', $h['title'], $h['reason']));
            }
            $rain = $weather->run($site, $execute);
            $this->line(sprintf('  Rain: %.1f in. (24h) / %.1f in. (48h) on %s — %s', $rain['inches_1d'], $rain['inches_2d'], $rain['date'] ?? '—', $rain['fired'] ? 'FIRED' : 'below threshold'));
            if ($rain['skipped'] !== null) {
                $this->line('    '.$rain['skipped']);
            }
            foreach ($rain['shares'] as $s) {
                $this->line(sprintf('    · %s → %s%s · "%s"', $s['post'], implode('+', $s['networks']), $s['location'] !== null ? ' ('.$s['location'].')' : '', $s['lead']));
            }
        }
        if (! $execute) {
            $this->comment('Report only — nothing written. Re-run with --execute.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function sites(): ?Collection
    {
        if ((bool) $this->option('all')) {
            return Site::withoutGlobalScopes()->orderBy('brand_name')->get();
        }
        $needle = trim((string) $this->argument('site'));
        if ($needle === '') {
            return collect();
        }
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        return $matches->values();
    }
}
