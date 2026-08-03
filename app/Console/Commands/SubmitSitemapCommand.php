<?php

namespace App\Console\Commands;

use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Submit a site's sitemap to Google Search Console for indexing (§ indexing). Uses the shared Google
 * grant + the site's picked GSC property; the sitemap is served on the site's own domain by the
 * companion plugin (/sitemap.xml). Idempotent — re-submitting just refreshes it. Reports the
 * submitted-URL count Google reports back.
 */
class SubmitSitemapCommand extends Command
{
    protected $signature = 'launchpad:submit-sitemap {--site= : Site id or brand name}';

    protected $description = 'Submit a site\'s sitemap to Google Search Console for indexing.';

    public function handle(SitemapSubmitter $submitter): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No matching site.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($sites as $site) {
            $result = $submitter->submit($site);

            if (! $result['ok']) {
                $failed++;
                $this->line("<error>✗</error> {$site->brand_name}: ".$this->reason($result['reason']));

                continue;
            }

            $this->line(sprintf(
                '<info>✓</info> %s — submitted %s (Google reports %d URL(s)%s).',
                $site->brand_name,
                (string) $result['sitemap'],
                $result['submitted'],
                $result['pending'] ? ', still processing' : '',
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reason(?string $reason): string
    {
        return match ($reason) {
            'not_connected' => 'Search Console not connected — connect Google and pick this site\'s GSC property first.',
            'no_domain' => 'the site has no domain URL set.',
            default => (string) $reason,
        };
    }

    /** @return Collection<int, Site> */
    private function resolveSites(): Collection
    {
        $arg = $this->option('site');
        if (is_string($arg) && $arg !== '') {
            return Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->get();
        }

        return Site::query()->orderBy('brand_name')->get();
    }
}
