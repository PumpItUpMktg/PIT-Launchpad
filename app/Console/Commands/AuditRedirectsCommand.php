<?php

namespace App\Console\Commands;

use App\Jobs\PublishRedirects;
use App\Locations\LegacyRedirectAuditor;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Produce a tenant's LEGACY location-redirect inventory (`/hoboken/` → `/hoboken-nj`) and, optionally, write
 * + push the corrective 301s. Preview by default — this is the "produce the inventory before writing any
 * rule" step. The FULL indexed inventory (every URL Google still holds) needs a prod crawl / GSC; this
 * covers every published location page + repoints any existing redirect whose target drifted (the
 * `/hoboken/` → blog-post case).
 */
class AuditRedirectsCommand extends Command
{
    protected $signature = 'launchpad:audit-redirects
        {--site= : Site id or brand name (required)}
        {--apply : Write the corrective redirects (default is a preview)}
        {--push : After applying, queue the redirect push to WordPress}';

    protected $description = 'Audit legacy location redirects (/hoboken/ → /hoboken-nj). Preview by default; --apply to write, --push to send to WP.';

    public function handle(LegacyRedirectAuditor $auditor): int
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

        $apply = (bool) $this->option('apply');
        $r = $auditor->audit($site, $apply);

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — legacy location redirects");

        foreach ($r['create'] as $row) {
            $this->line("  • <comment>new</comment>  {$row['from']} → {$row['to']}");
        }
        foreach ($r['fix'] as $row) {
            $this->line("  • <comment>fix</comment>  {$row['from']} → {$row['to']}  (was {$row['was']})");
        }
        foreach ($r['ok'] as $row) {
            $this->line("  • ok   {$row['from']} → {$row['to']}");
        }
        if ($r['skipped_live'] !== []) {
            $this->line('  • skipped (a live page owns the slug): '.implode(', ', $r['skipped_live']));
        }

        $changes = count($r['create']) + count($r['fix']);
        if ($changes === 0) {
            $this->newLine();
            $this->info('No legacy location redirects to add or fix.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->comment("Preview only — {$changes} redirect(s) would change. Re-run with --apply to write".($this->option('push') ? ', then push to WP.' : '.'));

            return self::SUCCESS;
        }

        $this->info("Wrote {$changes} redirect(s).");

        if ($this->option('push')) {
            PublishRedirects::dispatch((string) $site->id);
            $this->info('Queued the redirect push to WordPress (the plugin upserts by from_url, overriding stale rules).');
        } else {
            $this->comment('Not pushed — run with --push, or repush redirects, to send them to the live site.');
        }

        return self::SUCCESS;
    }
}
