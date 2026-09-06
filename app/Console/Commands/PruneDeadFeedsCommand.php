<?php

namespace App\Console\Commands;

use App\Enums\FeedOrigin;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Report (§6a ingestion): DEAD-FEED PRUNING — the actual cause of the ingest stall. Feeds are minted per
 * keyword × market (derived_from kw:{id}:mkt:{id}); Google News has no results for most, so the bulk of
 * every run is spent fetching feeds that produce nothing (e.g. ~9,000 of SPG's ~11,400). This DISABLES
 * (never deletes — a prune is reversible) the feeds not worth fetching:
 *
 *   - DEAD:   enabled, has been fetched at least once, NEVER returned an item, and has had a fair chance
 *             (created more than --grace-days ago). Days-since-creation is the retroactive proxy for "N
 *             fetch attempts" on the hourly schedule — it catches the existing backlog without needing an
 *             attempt counter, while protecting a brand-new feed that just hasn't produced yet.
 *   - SILENT: enabled, once produced an item, but nothing in --silence-days.
 *
 * REPORT-ONLY by default (prints the count per tenant, split by reason + origin, so client feeds are visible
 * before anything is disabled); --execute disables. Live-only, all tenants (or one via --site). Disabling
 * leaves the row + its history intact and re-enablable; the §6a generator will not re-enable it (PR 2).
 */
class PruneDeadFeedsCommand extends Command
{
    protected $signature = 'launchpad:prune-dead-feeds
        {--execute : Disable the dead/silent feeds (default is report-only)}
        {--site= : Limit to one site id or brand name}
        {--grace-days= : Days a never-producing feed is given before it is dead (default: config)}
        {--silence-days= : Days without an item before a once-producing feed is silent (default: config)}';

    protected $description = 'Disable feeds that never produce (report-only by default; --execute applies).';

    public function handle(): int
    {
        $siteId = $this->resolveSiteId();
        if ($siteId === false) {
            return self::FAILURE;
        }

        $grace = (int) ($this->option('grace-days') ?: config('launchpad.feeds.prune_grace_days', 14));
        $silence = (int) ($this->option('silence-days') ?: config('launchpad.feeds.prune_silence_days', 30));
        $execute = (bool) $this->option('execute');

        $deadBefore = Carbon::now()->subDays($grace);
        $silentBefore = Carbon::now()->subDays($silence);

        $sites = $siteId !== null
            ? Site::query()->whereKey($siteId)->get()
            : Site::query()->get();

        $this->info('Live-only · all tenants'.($siteId !== null ? ' (one site)' : '')
            ." · DEAD = fetched, never produced, older than {$grace}d · SILENT = no item in {$silence}d"
            .($execute ? ' — --execute: DISABLING.' : ' — report-only (pass --execute to disable).'));
        $this->newLine();

        $grandDead = 0;
        $grandSilent = 0;

        foreach ($sites as $site) {
            $dead = $this->dead($site->id, $deadBefore);
            $silent = $this->silent($site->id, $silentBefore);

            $deadCount = (clone $dead)->count();
            $silentCount = (clone $silent)->count();
            if ($deadCount === 0 && $silentCount === 0) {
                continue;
            }

            $enabled = Source::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('enabled', true)->count();
            $deadGenerated = (clone $dead)->where('origin', FeedOrigin::Generated->value)->count();
            $silentGenerated = (clone $silent)->where('origin', FeedOrigin::Generated->value)->count();

            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$enabled} enabled feed(s):");
            $this->line("  dead {$deadCount} (".($deadCount - $deadGenerated).' client, '.$deadGenerated.' generated)'
                ." · silent {$silentCount} (".($silentCount - $silentGenerated).' client, '.$silentGenerated.' generated)'
                .' · '.($deadCount + $silentCount).' of '.$enabled.' would be disabled');

            if ($execute) {
                $disabled = (clone $dead)->update(['enabled' => false]) + (clone $silent)->update(['enabled' => false]);
                $this->line("  <comment>disabled {$disabled}</comment>");
            }

            $grandDead += $deadCount;
            $grandSilent += $silentCount;
            $this->newLine();
        }

        $verb = $execute ? 'Disabled' : 'Would disable';
        $this->warn("{$verb} ".($grandDead + $grandSilent)." feed(s) total — {$grandDead} dead, {$grandSilent} silent.");
        if (! $execute && ($grandDead + $grandSilent) > 0) {
            $this->comment('Re-run with --execute to disable them (reversible — sources are disabled, never deleted).');
        }

        return self::SUCCESS;
    }

    /**
     * Enabled feeds that have been fetched but never produced an item, past their grace window.
     *
     * @return Builder<Source>
     */
    private function dead(string $siteId, Carbon $before): Builder
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('enabled', true)
            ->whereNull('last_item_at')
            ->whereNotNull('last_fetched_at')
            ->where('created_at', '<=', $before);
    }

    /**
     * Enabled feeds that once produced an item but have gone silent.
     *
     * @return Builder<Source>
     */
    private function silent(string $siteId, Carbon $before): Builder
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('enabled', true)
            ->whereNotNull('last_item_at')
            ->where('last_item_at', '<=', $before);
    }

    /** @return string|null|false site id (null = all tenants, false = resolution error) */
    private function resolveSiteId(): string|null|false
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return null;
        }

        $site = Site::withoutGlobalScope(VisibleSiteScope::class)
            ->where('id', $opt)->orWhere('brand_name', $opt)->first();

        if ($site === null) {
            $this->error("No site matches [{$opt}].");

            return false;
        }

        return (string) $site->id;
    }
}
