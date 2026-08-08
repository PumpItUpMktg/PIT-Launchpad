<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use Illuminate\Console\Command;

/**
 * Guarantee that every legacy URL 301s — no 404s. Unlike
 * {@see PlanLegacyRedirectsCommand} (impression-driven, leaves unconfident URLs
 * unresolved), this feeds the planner the FULL 404 list (a Search Console export
 * via `--from`, unioned with the GSC impression inventory) and covers every one:
 * confident successor where known, else the closest live hub, else the site root.
 *
 * Read-only by default (prints the plan grouped by how each URL was routed, with
 * the home-fallback bucket surfaced so an operator can hand-route those to a
 * better target). `--apply` upserts the 301 `Redirect` rows; the §2 publish path
 * pushes them to WordPress — this command never pushes.
 */
class CoverLegacy404sCommand extends Command
{
    protected $signature = 'launchpad:cover-legacy-404s
        {--site= : Site id or brand name (required)}
        {--from= : Path to a file of legacy URLs, one per line (e.g. a Search Console 404 export); # comments and blanks ignored}
        {--apply : Persist the 301 redirect rows}
        {--limit=25 : How many rows to print per reason group}';

    protected $description = 'Cover every legacy URL with a 301 (closest successor, else hub, else home) so nothing 404s.';

    public function handle(LegacyRedirectPlanner $planner): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }
        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $extraUrls = [];
        $from = trim((string) $this->option('from'));
        if ($from !== '') {
            if (! is_file($from) || ! is_readable($from)) {
                $this->error("Cannot read URL list [{$from}].");

                return self::FAILURE;
            }
            $extraUrls = $this->readUrls($from);
            $this->line(sprintf('  Read %d URL(s) from %s.', count($extraUrls), $from));
        }

        $plan = $planner->planCoverage($site, $extraUrls);
        $limit = max(1, (int) $this->option('limit'));

        $this->line("<info>{$site->brand_name}</info> — legacy 404 coverage plan");
        $this->line(sprintf(
            '  %d inputs → %d redirect (301), %d already-live, %d already-redirected/claimed.',
            $plan['inputs'], count($plan['redirect']), $plan['skipped_live'], $plan['already'],
        ));

        if ($plan['by_reason'] !== []) {
            $this->newLine();
            $this->line('  <comment>Routing breakdown:</comment>');
            arsort($plan['by_reason']);
            foreach ($plan['by_reason'] as $reason => $count) {
                $this->line(sprintf('    %5d  %s', $count, $reason));
            }
        }

        // Surface the home-fallback bucket — these could not be confidently routed and
        // 301 to the site root; an operator may want to hand-route them to a better page.
        $home = array_values(array_filter($plan['redirect'], fn (array $r): bool => $r['reason'] === 'fallback_home'));
        if ($home !== []) {
            $this->newLine();
            $this->line(sprintf('  <comment>Home-fallback (%d — no confident successor, review these):</comment>', count($home)));
            foreach (array_slice($home, 0, $limit) as $r) {
                $this->line(sprintf('    %6d  %s  →  /', $r['impressions'], $r['from']));
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('  <comment>Dry run — re-run with --apply to persist. §2 publish pushes the rows to WordPress.</comment>');

            return self::SUCCESS;
        }

        $written = $planner->apply($site, $plan);
        $this->newLine();
        $this->info("  Applied {$written} redirect row(s). Every listed legacy URL now 301s — none will 404.");

        return self::SUCCESS;
    }

    /**
     * Parse a URL list file: one URL/path per line, `#` comments and blank lines ignored.
     *
     * @return list<string>
     */
    private function readUrls(string $path): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
        $urls = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $urls[] = $line;
        }

        return array_values(array_unique($urls));
    }
}
