<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Drip\PublishDrip;
use Illuminate\Console\Command;

/**
 * The publish drip from the console: report a site's queue (default), turn the drip on/off, set its batch
 * or stale days, or release the next batch now.
 */
class PublishDripCommand extends Command
{
    protected $signature = 'launchpad:publish-drip
        {--site= : Site id or brand name (required)}
        {--on : turn the drip on}
        {--off : turn the drip off}
        {--batch= : pages per batch}
        {--stale-days= : days after which a waiting page stops counting against the batch}
        {--release : release the next batch now (as many as there are slots)}';

    protected $description = 'Report, configure, or release the publish drip for a site';

    public function handle(PublishDrip $drip): int
    {
        $arg = $this->option('site');
        $site = is_string($arg) && $arg !== '' ? Site::withoutGlobalScopes()->where('id', $arg)->orWhere('brand_name', $arg)->first() : null;
        if ($site === null) {
            $this->error('Pass --site=<id or brand name>.');

            return self::FAILURE;
        }

        $changes = [];
        if ($this->option('on')) {
            $changes['enabled'] = true;
        }
        if ($this->option('off')) {
            $changes['enabled'] = false;
        }
        if (is_numeric($this->option('batch'))) {
            $changes['batch'] = (int) $this->option('batch');
        }
        if (is_numeric($this->option('stale-days'))) {
            $changes['stale_days'] = (int) $this->option('stale-days');
        }
        if ($changes !== []) {
            $drip->configure($site, $changes);
            $site->refresh();
        }

        if ($this->option('release')) {
            $released = $drip->release($site);
            $this->info($released === [] ? 'Nothing released — no slots free, or the queue is empty.' : 'Released '.count($released).' page(s).');
        }

        $status = $drip->status($site);
        $s = $status['settings'];
        $this->info(sprintf('Publish drip — %s: %s · batch %d · a page stops counting after %d days', $site->brand_name, $s['enabled'] ? 'ON' : 'off', $s['batch'], $s['stale_days']));
        $this->line(sprintf('In flight (published, not yet indexed): %d · free slots: %d · queued: %d', count($status['in_flight']), $status['slots'], count($status['queued'])));
        if ($status['queued'] !== []) {
            $this->table(['#', 'Queued page', 'Kind', 'Population'], array_map(fn (int $i, array $r): array => [$i + 1, $r['title'], $r['kind'], $r['population'] > 0 ? number_format($r['population']) : ''], array_keys($status['queued']), $status['queued']));
        }

        return self::SUCCESS;
    }
}
