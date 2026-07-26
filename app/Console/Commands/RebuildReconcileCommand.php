<?php

namespace App\Console\Commands;

use App\ContentEngine\Reconcile\RebuildReconciler;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The orchestrated "Rebuild & reconcile" cascade (§B slice 4), run from the CLI. Re-aligns a site's
 * downstream references (keywords → categories → posts → towns → bounded republish) to its current silo
 * tree; with --structure it first rewrites the §4 blueprint from services and re-materializes pages.
 * Idempotent and safe to re-run — a no-op when nothing drifted.
 */
class RebuildReconcileCommand extends Command
{
    protected $signature = 'launchpad:rebuild-reconcile {site : Site id or brand name}
        {--structure : Also rewrite the silo structure from services and re-materialize pages first}';

    protected $description = 'Run the Rebuild & reconcile cascade — re-align posts/keywords/categories/towns to the current silo tree and republish the affected live content.';

    public function handle(RebuildReconciler $reconciler): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $report = $reconciler->reconcile($site, (bool) $this->option('structure'));

        $this->line("<info>{$site->brand_name}</info> — ".$report->summary());

        foreach ($report->errors as $error) {
            $this->warn("  stage [{$error['stage']}]: {$error['message']}");
        }

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }
}
