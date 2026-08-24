<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Geo\GeoVisibilityAudit;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Measure AI-search (GEO) visibility for a site's active prompts and store the durable snapshots the
 * operator GEO board reads. Budget-bounded + freshness-cached per {@see GeoVisibilityAudit}; safe to run
 * weekly. With no {site} it sweeps every non-onboarding site.
 */
class SyncGeoCommand extends Command
{
    protected $signature = 'sandhog:sync-geo {site? : Site id, brand name, or domain (partial ok); omit to sweep all}';

    protected $description = 'Measure AI-search visibility (GEO) for a site\'s active prompts.';

    public function handle(GeoVisibilityAudit $audit): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $r = $audit->audit($site);
            if (! $r['enabled']) {
                $this->warn('No AI search engine is configured (set ANTHROPIC_API_KEY and/or PERPLEXITY_API_KEY) — nothing measured.');

                return self::SUCCESS;
            }
            $this->line(sprintf(
                '<info>%s</info> — %d measured, %d still fresh, %d deferred (across %d engine(s), %d checks).',
                $site->brand_name, $r['measured'], $r['skipped_fresh'], $r['deferred'], $r['engines'], $r['total'],
            ));
        }

        return self::SUCCESS;
    }

    /** @return iterable<Site>|null */
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
