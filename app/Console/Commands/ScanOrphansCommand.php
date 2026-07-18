<?php

namespace App\Console\Commands;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\OrphanScanner;
use Illuminate\Console\Command;

/**
 * Scan a site (or every site) for page-integrity problems left by deletions — orphaned child pages,
 * pages deleted here but still live on WordPress, and retired URLs with no 301. Read-only: it reports,
 * it never changes anything. Run after deleting pages, or on a schedule, to make sure nothing is
 * silently orphaned.
 */
class ScanOrphansCommand extends Command
{
    protected $signature = 'launchpad:scan-orphans {site? : a Site id (omit with --all)} {--all : scan every site}';

    protected $description = 'Report orphaned pages, stranded-live pages, and URLs that need a 301 for a site.';

    public function handle(OrphanScanner $scanner): int
    {
        $sites = $this->option('all')
            ? Site::withoutGlobalScope(SiteScope::class)->orderBy('brand_name')->get()
            : Site::withoutGlobalScope(SiteScope::class)->where('id', (string) $this->argument('site'))->get();

        if ($sites->isEmpty()) {
            $this->error($this->option('all') ? 'No sites found.' : 'Site not found — pass a Site id or --all.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($sites as $site) {
            $findings = $scanner->scan($site);
            $total += count($findings);

            if ($findings === []) {
                $this->line(sprintf('✓ %s — no orphans.', $site->brand_name));

                continue;
            }

            $this->warn(sprintf('%s — %d issue(s):', $site->brand_name, count($findings)));
            $this->table(
                ['Type', 'URL', 'Page', 'Fix'],
                array_map(fn ($f): array => [$f->type->label(), $f->url, $f->title, $f->type->fix()], $findings),
            );
        }

        $this->newLine();
        $this->line($total === 0 ? 'All clear — no orphans across '.$sites->count().' site(s).' : "Found {$total} issue(s) to resolve.");

        return self::SUCCESS;
    }
}
