<?php

namespace App\Console\Commands;

use App\ColdOutreach\ColdPipeline;
use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

class ColdAdvanceCommand extends Command
{
    protected $signature = 'launchpad:cold-advance {--site= : one site id (default: every site with cold outreach enabled)}';

    protected $description = 'Run the cold outreach pipeline now: verify imports (Bird) → enroll in Instantly → sync engagement → promote warm contacts to Brevo.';

    public function handle(ColdPipeline $pipeline): int
    {
        $ids = $this->option('site') !== null
            ? [(string) $this->option('site')]
            : ColdOutreachConfig::withoutGlobalScope(SiteScope::class)->where('enabled', true)->pluck('site_id')->map('strval')->all();

        $failed = false;
        foreach ($ids as $id) {
            $site = Site::withoutGlobalScope(SiteScope::class)->find($id);
            if ($site === null) {
                $this->error("Unknown site {$id}.");
                $failed = true;

                continue;
            }
            $this->line("<options=bold>{$site->name}</>");
            foreach ($pipeline->advance($site) as $step => $outcome) {
                if ($outcome['error'] !== null) {
                    $failed = true;
                    $this->warn("  {$step}: stopped — {$outcome['error']}");
                } else {
                    $this->line("  {$step}: {$outcome['done']}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
