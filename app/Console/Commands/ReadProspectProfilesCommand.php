<?php

namespace App\Console\Commands;

use App\Jobs\ReadProspectProfiles;
use App\Links\Profile\ProspectProfiler;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * launchpad:read-prospect-profiles — read the organisation profile (who, where, contacts, offer,
 * thumbnail) for a site's unread local-opportunity prospects, most local first, bounded.
 */
class ReadProspectProfilesCommand extends Command
{
    protected $signature = 'launchpad:read-prospect-profiles
        {--site= : The site ULID}
        {--kind= : One lane only: sponsorship | membership | dealer_locator | job_board}
        {--limit=40 : How many unread prospects to read this run (max 200)}';

    protected $description = 'Read who / where / contacts / offer / thumbnail for a site\'s unread link opportunities (two page reads + one cheap model call each).';

    public function handle(ProspectProfiler $profiler): int
    {
        $site = Site::withoutGlobalScopes()->find((string) $this->option('site'));
        if ($site === null) {
            $this->error('Pass --site=<ulid>.');

            return self::FAILURE;
        }
        $kind = $this->option('kind') !== null ? (string) $this->option('kind') : null;
        $queue = ReadProspectProfiles::pending((string) $site->id, $kind, (int) $this->option('limit'));
        if ($queue === []) {
            $this->line("<info>{$site->brand_name}</info> — nothing unread.");

            return self::SUCCESS;
        }
        $this->line(sprintf('<info>%s</info> — reading %d profile(s):', $site->brand_name, count($queue)));
        $ok = $facts = $failed = 0;
        foreach ($queue as $p) {
            $p = $profiler->read($p);
            if ($p->profile_error !== null && $p->profile === null) {
                $failed++;
                $this->line(sprintf('  ✗ %-40s %s', $p->domain, $p->profile_error));

                continue;
            }
            $p->profile_model_ok ? $ok++ : $facts++;
            $line = ProspectProfiler::offerLine($p->profile);
            $this->line(sprintf('  %s %-40s %s%s', $p->profile_model_ok ? '•' : '◦', $p->domain, (string) ($p->profile['name'] ?? ''), $line !== null ? "  — {$line}" : ''));
        }
        $this->line(sprintf('Done: %d profiled, %d facts-only (model did not answer), %d unreadable.', $ok, $facts, $failed));

        return self::SUCCESS;
    }
}
