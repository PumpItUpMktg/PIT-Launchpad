<?php

namespace App\Console\Commands;

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Submit a site's published URLs to IndexNow (Bing / Yandex / Seznam / Naver). Mints + deploys the
 * site's key to the companion plugin on first use, then pings every published page + post. Free, no
 * per-request auth. Google does not participate (the sitemap submission covers Google).
 */
class PingIndexNowCommand extends Command
{
    protected $signature = 'launchpad:indexnow {--site= : Site id or brand name}';

    protected $description = 'Submit a site\'s published URLs to IndexNow (Bing/Yandex/…).';

    public function handle(IndexNowSubmitter $submitter): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No matching site.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($sites as $site) {
            $result = $submitter->submitSite($site);

            if ($result['ok']) {
                $this->line("<info>✓</info> {$site->brand_name} — submitted {$result['submitted']} URL(s) to IndexNow.");
            } else {
                $failed++;
                $this->line("<error>✗</error> {$site->brand_name}: ".(string) $result['reason']);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
