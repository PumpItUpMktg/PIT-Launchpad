<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Collapse DUPLICATE town pages down to one canonical page per town — the recovery when a
 * re-materialize (or a re-run of the build) minted many Content rows for the same physical town, so a
 * site shows e.g. 1,100 town pages for ~20 selected towns. Left alone, "publish the selected towns"
 * would push every duplicate to WordPress.
 *
 * A town page is a `page` with `page_type=Location`, no `location_id` (that's the hub), no
 * `primary_service_id`, and a `parent_location_id` (the GBP location it hangs under). Two town pages
 * are the SAME town when they share `(parent_location_id, townKey(title))` — the same normalization the
 * Operate directory uses to line a coverage row up with its page.
 *
 * Per duplicate group the canonical row is the one furthest along and oldest: a `published` (live) page
 * always wins (never delete a live page); else the one with a real draft; else the earliest-created.
 * Every other row in the group is SOFT-deleted (Content soft-deletes — recoverable). A non-canonical
 * row that is itself live on WordPress is never touched; it's reported so the operator can resolve it.
 *
 * PREVIEW BY DEFAULT (this removes rows): the command lists what it would collapse and changes nothing
 * until `--apply`. `--location=` scopes to one GBP location.
 */
class DedupeTownPagesCommand extends Command
{
    protected $signature = 'launchpad:dedupe-town-pages {site : Site id or brand name}
        {--apply : Actually soft-delete the duplicates (default is a preview that changes nothing)}
        {--location= : Limit to one GBP location id (parent_location_id)}';

    protected $description = 'Collapse duplicate town pages to one canonical page per town (preview by default; --apply to soft-delete the extras).';

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
            ->whereNull('primary_service_id')
            ->whereNotNull('parent_location_id');

        $location = trim((string) $this->option('location'));
        if ($location !== '') {
            $query->where('parent_location_id', $location);
        }

        $townPages = $query->get();

        // Group by the physical town: parent GBP location + normalized town name.
        $groups = $townPages->groupBy(
            fn (Content $c): string => $c->parent_location_id.'|'.$this->townKey((string) $c->title)
        );

        $dupeGroups = $groups->filter(fn (Collection $g): bool => $g->count() > 1);

        if ($dupeGroups->isEmpty()) {
            $this->info("{$site->brand_name}: no duplicate town pages — {$groups->count()} town(s), one page each.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $toRemove = 0;
        $liveConflicts = 0;

        $this->line("<info>{$site->brand_name}</info> — {$dupeGroups->count()} town(s) with duplicates "
            ."across {$townPages->count()} town page(s):");

        foreach ($dupeGroups as $group) {
            [$canonical, $duplicates, $liveExtras] = $this->partition($group);

            $liveConflicts += $liveExtras->count();
            $removable = $duplicates->reject(fn (Content $c): bool => $liveExtras->contains('id', $c->id));
            $toRemove += $removable->count();

            $town = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', (string) $canonical->title));
            $this->line("  • <comment>{$town}</comment> — keep 1 ({$canonical->status->value}"
                .($canonical->status === ContentStatus::Published ? ', live' : ($canonical->hasDraft() ? ', drafted' : '')).')'
                .", remove {$removable->count()}"
                .($liveExtras->isNotEmpty() ? ", <error>{$liveExtras->count()} live duplicate(s) kept — resolve manually</error>" : ''));

            if ($apply && $removable->isNotEmpty()) {
                Content::withoutGlobalScope(SiteScope::class)
                    ->whereIn('id', $removable->pluck('id')->all())->delete(); // soft delete
            }
        }

        $this->newLine();
        if ($liveConflicts > 0) {
            $this->warn("{$liveConflicts} non-canonical page(s) are live on WordPress — left in place. "
                .'Take them down (Operate → Locations → Take down) before they can be removed.');
        }

        if (! $apply) {
            $this->comment("Preview only — nothing changed. Re-run with --apply to soft-delete {$toRemove} duplicate page(s).");

            return self::SUCCESS;
        }

        $this->line("Removed {$toRemove} duplicate town page(s) (soft-deleted, recoverable). "
            .'Each town now resolves to one canonical page — publish the selected towns from Operate → Locations.');

        return self::SUCCESS;
    }

    /**
     * Split a same-town group into the canonical keeper, the duplicates, and any non-canonical rows
     * that are live on WordPress (never auto-removed).
     *
     * @param  Collection<int, Content>  $group
     * @return array{0: Content, 1: Collection<int, Content>, 2: Collection<int, Content>}
     */
    private function partition(Collection $group): array
    {
        // Furthest-along + oldest wins: live page first, then a real draft, then earliest created.
        $ordered = $group->sortByDesc(fn (Content $c): array => [
            $c->status === ContentStatus::Published ? 1 : 0,
            $c->hasDraft() ? 1 : 0,
            -($c->created_at->timestamp),
        ])->values();

        $canonical = $ordered->first();
        $duplicates = $ordered->slice(1)->values();
        $liveExtras = $duplicates->filter(
            fn (Content $c): bool => $c->status === ContentStatus::Published || $c->wp_post_id !== null
        )->values();

        return [$canonical, $duplicates, $liveExtras];
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors PhysicalLocations. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
