<?php

namespace App\Console\Commands;

use App\Jobs\PublishRedirects;
use App\Locations\ForeignRedirectImporter;
use App\Models\Site;
use App\Publishing\PublishRedirectsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fold another redirect plugin's rules into the control-plane store so the plugin can be switched off
 * (the companion reads them from the plugin's own tables — no plugin UI needed). Preview by default;
 * --apply writes the rules only the plugin knew, --push queues the WP push, --now pushes from this process.
 * Conflicts (the plugin and we disagree on a destination) are listed, never auto-resolved.
 */
class ImportForeignRedirectsCommand extends Command
{
    protected $signature = 'launchpad:import-foreign-redirects
        {--site= : Site id or brand name}
        {--apply : Write the rules only the other plugin knows (default is a preview)}
        {--push : After applying, queue the redirect push to WordPress}
        {--now : After applying, push to WordPress from this process}';

    protected $description = 'Import the redirect rules another plugin on the live site holds into the control-plane store (preview by default).';

    public function handle(ForeignRedirectImporter $importer, PublishRedirectsService $redirects): int
    {
        $arg = trim((string) $this->option('site'));
        $site = $arg !== '' ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first() : null;
        if ($site === null) {
            $this->error($arg === '' ? 'Pass --site=<id or brand name>.' : "No site matches [{$arg}].");

            return self::FAILURE;
        }

        try {
            $plan = $importer->plan($site);
        } catch (Throwable $e) {
            $this->error('Could not read the other plugin\'s rules: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('<info>%s</info> — %d foreign redirect rule(s) found on the live site', $site->brand_name, $plan['total']));
        foreach ($plan['tables'] as $t) {
            $this->line(sprintf('  table %s — %d row(s)', $t['table'] ?? '?', (int) ($t['rows'] ?? 0)));
        }
        foreach ($plan['options'] as $o) {
            $this->line(sprintf('  option %s — %s', $o['name'] ?? '?', $o['summary'] ?? ''));
        }
        foreach ($plan['new'] as $r) {
            $this->line(sprintf('  • <fg=green>new</>      %s → %s (%d%s)', $r['from'], $r['to'] !== '' ? $r['to'] : '(gone)', $r['code'], $r['hits'] !== null && $r['hits'] > 0 ? ", {$r['hits']} hit(s)" : ''));
        }
        foreach ($plan['conflict'] as $r) {
            $this->line(sprintf('  • <fg=red>conflict</> %s — plugin → %s · ours → %s — settle with launchpad:fix-redirect', $r['from'], $r['to'] !== '' ? $r['to'] : '(gone)', $r['ours']));
        }
        foreach ($plan['live'] as $r) {
            $this->line(sprintf('  • <fg=yellow>live</>     %s is a live page of ours — not redirected (plugin sent it to %s)', $r['from'], $r['to']));
        }
        foreach ($plan['unparsed'] as $r) {
            $this->line(sprintf('  • <fg=yellow>skipped</>  %s → %s — %s', $r['from'] !== '' ? $r['from'] : '(empty)', $r['to'] !== '' ? $r['to'] : '(empty)', $r['why']));
        }
        $this->line(sprintf('  %d covered by our store already, %d new, %d conflict(s), %d live, %d skipped.',
            count($plan['covered']), count($plan['new']), count($plan['conflict']), count($plan['live']), count($plan['unparsed'])));

        if (! $this->option('apply')) {
            $this->comment('Preview only — nothing changed. Re-run with --apply to write the new rules'.($this->option('push') || $this->option('now') ? ', then push to WP.' : '.'));

            return self::SUCCESS;
        }

        $written = $importer->apply($site, $plan);
        $this->info("Wrote {$written} redirect(s).");

        if ($this->option('now')) {
            try {
                $redirects->publish($site);
                $this->info('Pushed to WordPress now.');
            } catch (Throwable $e) {
                $this->error('Push to WordPress failed: '.$e->getMessage());

                return self::FAILURE;
            }
        } elseif ($this->option('push')) {
            PublishRedirects::dispatch((string) $site->id);
            $this->info('Queued the redirect push to WordPress.');
        } else {
            $this->comment('Not pushed — run with --push (queue) or --now (this process) to send them to the live site.');
        }

        return self::SUCCESS;
    }
}
