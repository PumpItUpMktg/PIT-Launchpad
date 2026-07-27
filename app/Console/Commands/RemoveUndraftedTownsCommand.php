<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipe a tenant's UNDRAFTED town pages — the "clear the ready-to-generate queue and start over" tool
 * when the plan filled with half-built / duplicate town rows (e.g. bridgewater-nj-3, mount-olive-nj-5).
 *
 * Targets TOWN pages only: a `page` with `page_type=Location`, no `location_id` (that's the hub), a
 * `parent_location_id`, that has NO real draft yet (`! hasDraft()`) and is NOT published. So it removes
 * exactly the "Ready to generate / Generating / Draft failed" rows and never touches a drafted-in-review
 * or live page.
 *
 * Unlike a soft reject, this is the durable removal the per-page "Remove" does — for each target it:
 *   - deletes the `BuildPage` PLAN row (so a later Sync / rebuild can't re-materialize it), and
 *   - flushes any pending/dead queue job that referenced it, and
 *   - soft-deletes the `Content` row.
 * The town's `page_selected` flag is left as-is (matching the per-page Remove): the town stays selected,
 * so "Generate drafts" mints ONE fresh page per town — a clean start, no duplicates.
 *
 * PREVIEW BY DEFAULT (this removes rows): lists what it would wipe and changes nothing until `--apply`.
 * `--location=` scopes to one GBP location (parent_location_id).
 */
class RemoveUndraftedTownsCommand extends Command
{
    protected $signature = 'launchpad:remove-undrafted-towns {site : Site id or brand name}
        {--apply : Actually remove the pages + plan rows (default is a preview that changes nothing)}
        {--location= : Limit to one GBP location id (parent_location_id)}';

    protected $description = 'Wipe a tenant\'s undrafted (ready-to-generate) town pages + their plan rows — clear the queue and start over (preview by default; --apply to remove).';

    public function handle(): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $query = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->where('status', '!=', ContentStatus::Published->value);

        $location = trim((string) $this->option('location'));
        if ($location !== '') {
            $query->where('parent_location_id', $location);
        }

        // Undrafted only: no real draft yet (Ready to generate / Generating / Draft failed).
        $targets = $query->get()->filter(fn (Content $c): bool => ! $c->hasDraft())->values();

        if ($targets->isEmpty()) {
            $this->info("{$site->brand_name}: nothing to remove — no undrafted town pages.");

            return self::SUCCESS;
        }

        $this->line("<info>{$site->brand_name}</info> — {$targets->count()} undrafted town page(s) to remove:");
        foreach ($targets->take(30) as $c) {
            $this->line("  • {$c->title}  ·  /{$c->slug}  ·  {$c->status->value}");
        }
        if ($targets->count() > 30) {
            $this->line('  … and '.($targets->count() - 30).' more.');
        }

        if (! $this->option('apply')) {
            $this->comment("Preview only — nothing changed. Re-run with --apply to remove these {$targets->count()} page(s) + their plan rows.");

            return self::SUCCESS;
        }

        $ids = $targets->pluck('id')->all();

        // Delete the plan rows first so a later Sync / rebuild can't re-materialize what we're wiping.
        $plans = BuildPage::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('content_id', $ids)->delete();

        $jobs = $this->flushJobsFor($ids);

        Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->delete(); // soft delete

        $this->line("Removed {$targets->count()} undrafted town page(s), {$plans} plan row(s), and "
            ."{$jobs} stuck/queued job(s). The towns stay selected — \"Generate drafts\" now mints one fresh page each.");

        return self::SUCCESS;
    }

    /**
     * Delete the pending `jobs` + dead `failed_jobs` rows whose serialized payload references one of the
     * removed content ids, so a wiped page can't re-fire when the worker returns. ULIDs are unique, so a
     * substring match is a safe scope.
     *
     * @param  list<string>  $contentIds
     */
    private function flushJobsFor(array $contentIds): int
    {
        if ($contentIds === []) {
            return 0;
        }

        $match = function ($q) use ($contentIds): void {
            foreach ($contentIds as $id) {
                $q->orWhere('payload', 'like', '%'.$id.'%');
            }
        };

        return DB::table('jobs')->where($match)->delete()
            + DB::table('failed_jobs')->where($match)->delete();
    }
}
