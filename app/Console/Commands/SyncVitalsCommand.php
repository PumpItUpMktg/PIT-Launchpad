<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Operator\CoreWebVitalsAudit;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Measure Core Web Vitals (PageSpeed Insights) for a site's published pages and store the durable
 * readings the client "Site speed" card reads. Budget-bounded + freshness-cached per {@see CoreWebVitalsAudit},
 * so it's safe to run weekly. With no {site} it sweeps every non-onboarding site.
 */
class SyncVitalsCommand extends Command
{
    protected $signature = 'sandhog:sync-vitals {site? : Site id, brand name, or domain (partial ok); omit to sweep all}';

    protected $description = 'Measure Core Web Vitals for a site\'s published pages (PageSpeed Insights).';

    public function handle(CoreWebVitalsAudit $audit): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $r = $audit->audit($site);
            if (! $r['enabled']) {
                $this->warn('PageSpeed Insights is disabled (services.pagespeed.enabled) — nothing measured.');

                return self::SUCCESS;
            }
            $this->line(sprintf(
                '<info>%s</info> — %d measured, %d still fresh, %d deferred (of %d pages).',
                $site->brand_name, $r['measured'], $r['skipped_fresh'], $r['deferred'], $r['total'],
            ));
        }

        return self::SUCCESS;
    }

    /** @return iterable<Site>|null the resolved site(s), or null when a named site didn't resolve. */
    private function resolveSites(): ?iterable
    {
        $needle = $this->argument('site');
        if ($needle === null) {
            return Site::query()->where('status', '!=', SiteStatus::Onboarding->value)->get();
        }

        $matches = SiteFinder::matches((string) $needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        return [$matches->first()];
    }
}
