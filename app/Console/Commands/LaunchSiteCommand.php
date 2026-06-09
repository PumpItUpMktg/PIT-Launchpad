<?php

namespace App\Console\Commands;

use App\Enums\LaunchRunStatus;
use App\Models\Site;
use App\Publishing\LaunchOrchestrator;
use Illuminate\Console\Command;

class LaunchSiteCommand extends Command
{
    protected $signature = 'launchpad:launch-site {site : Site id or brand name}';

    protected $description = 'Push a built site to its connected WordPress instance (silos → content → redirects).';

    public function handle(LaunchOrchestrator $orchestrator): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $run = $orchestrator->launch($site);

        if ($run->status === LaunchRunStatus::Blocked) {
            $this->error("Launch blocked: no present, non-compromised WordPress connection for {$site->brand_name}. Wire one first.");

            return self::FAILURE;
        }

        $this->info("Launched {$site->brand_name} — ".$run->summary());

        foreach ($run->items ?? [] as $item) {
            $this->line(sprintf(
                '  [%s] %s: %s%s%s',
                $item['state'] ?? '',
                $item['kind'] ?? '',
                $item['label'] ?? '',
                isset($item['wp_id']) ? " (wp #{$item['wp_id']})" : '',
                ($item['message'] ?? '') !== '' ? ' — '.$item['message'] : '',
            ));
        }

        return self::SUCCESS;
    }

    private function resolveSite(string $arg): ?Site
    {
        return Site::query()->find($arg)
            ?? Site::query()->where('brand_name', $arg)->first();
    }
}
