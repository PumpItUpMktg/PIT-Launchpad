<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Publishing\Links\DeadLinkAudit;
use Illuminate\Console\Command;

/**
 * Report (read-only): how many GENERATED internal links in published content would 404 — a baked href whose
 * target is neither a published page nor an active redirect. See {@see DeadLinkAudit}. This is the count
 * that scopes the rest of the fix: mostly the SiloNesting slug-rewrite leaving stale hrefs (fixed durably
 * by a 301-on-slug-change), plus the smaller AI-hallucinated-path case (a pre-publish link validator).
 *
 * READ-ONLY, all tenants (or one via --site). --limit caps the per-site sample of offending pages.
 */
class ReportDeadInternalLinksCommand extends Command
{
    protected $signature = 'launchpad:report-dead-internal-links
        {--site= : Limit to one site id or brand name}
        {--limit=20 : Max sample pages + target paths to print per site}';

    protected $description = 'Report (read-only) generated internal links in published content that would 404 (no page, no redirect).';

    public function handle(DeadLinkAudit $audit): int
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

        $limit = max(1, (int) $this->option('limit'));
        $this->info('Read-only · generated internal links in published content that would 404 (no page + no redirect).');

        $grandScanned = 0;
        $grandDead = 0;
        foreach ($sites as $site) {
            $result = $audit->audit($site, $limit);
            $grandScanned += $result['scanned'];
            $grandDead += $result['dead'];
            if ($result['dead'] === 0) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — <fg=red>{$result['dead']}</> dead of {$result['scanned']} internal link(s):");

            $this->line('  <comment>Top dead targets</comment> (path × count):');
            foreach (array_slice($result['by_target'], 0, $limit, true) as $path => $count) {
                $this->line("      {$count}×  {$path}");
            }

            $this->line('  <comment>Sample pages</comment>:');
            foreach ($result['samples'] as $s) {
                $this->line("      \"{$s['title']}\" ({$s['slug']}) → {$s['href']}");
            }
        }

        $this->newLine();
        if ($grandDead === 0) {
            $this->info("Clean — no dead internal links across {$grandScanned} scanned.");

            return self::SUCCESS;
        }

        $this->warn("{$grandDead} dead internal link(s) of {$grandScanned} scanned across all tenants. Most are likely stale SiloNesting slug rewrites — a 301-on-slug-change auto-heals them; a pre-publish validator catches new hallucinated paths.");

        return self::SUCCESS;
    }
}
