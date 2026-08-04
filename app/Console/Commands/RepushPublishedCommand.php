<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Models\Site;
use App\Publishing\RepushPublished;
use Illuminate\Console\Command;

/**
 * Bulk re-push a site's published content to refresh the engine-owned meta-blob (canonical / og:url /
 * schema) — e.g. to fix canonicals baked with the old staging host before a domain move. Idempotent,
 * no fal spend. Throttled into waves so the client WordPress isn't flooded. Needs a running queue
 * worker to drain (the queue-health banner + drain button are the backstop).
 */
class RepushPublishedCommand extends Command
{
    protected $signature = 'launchpad:repush-published
        {--site= : Site id or brand name (required)}
        {--kind=post : post | page | all}
        {--chunk= : URLs per wave (default from config)}
        {--interval= : Seconds between waves (default from config)}
        {--dry-run : Count what would be re-pushed without dispatching}';

    protected $description = 'Re-push a site\'s published posts/pages to refresh canonical/og/schema (throttled).';

    public function handle(RepushPublished $repush): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Pass --site= (id or brand name).');

            return self::FAILURE;
        }

        $kinds = $this->kinds();
        if ($kinds === null) {
            $this->error('Invalid --kind — use post, page, or all.');

            return self::FAILURE;
        }

        $chunk = $this->intOption('chunk', (int) config('launchpad.repush.chunk', 10));
        $interval = $this->intOption('interval', (int) config('launchpad.repush.interval_seconds', 15));
        $dryRun = (bool) $this->option('dry-run');

        $result = $repush->dispatch($site, $kinds, $chunk, $interval, $dryRun);

        if ($result['count'] === 0) {
            $this->line("{$site->brand_name}: no published ".$this->option('kind').'(s) to re-push.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d published item(s) for %s in %d wave(s) of %d (~%s min to fully queue).',
            $dryRun ? 'Would re-push' : 'Re-pushing',
            $result['count'],
            $site->brand_name,
            $result['waves'],
            $chunk,
            $result['minutes'],
        ));

        if (! $dryRun) {
            $this->line('Ensure the queue worker is running so the waves drain (watch the queue-health banner).');
            if ($result['sitemap_submitted']) {
                $this->line('Sitemap will be resubmitted to Google Search Console once the waves land.');
            }
        }

        return self::SUCCESS;
    }

    /** @return list<ContentKind>|null */
    private function kinds(): ?array
    {
        return match ((string) $this->option('kind')) {
            'post' => [ContentKind::Post],
            'page' => [ContentKind::Page],
            'all' => [ContentKind::Post, ContentKind::Page],
            default => null,
        };
    }

    private function intOption(string $name, int $default): int
    {
        $raw = $this->option($name);

        return is_numeric($raw) ? max(1, (int) $raw) : $default;
    }

    private function resolveSite(): ?Site
    {
        $arg = $this->option('site');
        if (! is_string($arg) || $arg === '') {
            return null;
        }

        return Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
    }
}
