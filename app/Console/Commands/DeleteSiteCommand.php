<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Site;
use App\Operator\SiteDeleter;
use Illuminate\Console\Command;

/**
 * Permanently DELETE a tenant (Site) and all of its data — the destructive sibling of
 * {@see ResetTenantCommand} (which only rewinds onboarding and keeps the site row). Use when a
 * duplicate/abandoned tenant must be removed entirely (e.g. re-running the build created a second
 * instance of the same brand). The actual erase lives in {@see SiteDeleter} (shared with the operator
 * portfolio's row action); this command is the CLI surface — resolve, confirm, report.
 *
 * Guards: refuses a `live` (client-handed-over) tenant; refuses an ambiguous brand-name match
 * (lists the candidate ids so you pass the exact ULID); a named confirmation (`--force` to skip);
 * `--dry-run` reports without changing anything. WordPress is left alone unless `--purge-wordpress`.
 */
class DeleteSiteCommand extends Command
{
    protected $signature = 'launchpad:delete-site
        {site : Site id (ULID), or exact brand_name when it is unique}
        {--purge-wordpress : also force-delete this site\'s published pages on WordPress (DANGER if the WP instance is shared with another tenant)}
        {--with-account : also delete the owning Account if it has no other sites left}
        {--force : skip the confirmation prompt}
        {--dry-run : report what would be deleted without changing anything}';

    protected $description = 'Permanently delete a tenant (Site) and all its data. WordPress is left alone unless --purge-wordpress. Dry-run / confirmation guarded.';

    public function handle(SiteDeleter $deleter): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            return self::FAILURE; // resolveSite already reported why (not found / ambiguous)
        }

        // A live (client-handed-over) tenant is production — never delete it from this tool.
        if ($deleter->isLive($site)) {
            $this->error("Refusing to delete '{$site->brand_name}' ({$site->id}) — it is LIVE (handed to a client).");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $purgeWp = (bool) $this->option('purge-wordpress');
        $withAccount = (bool) $this->option('with-account');

        $published = $deleter->publishedPages($site);
        $counts = $deleter->counts($site);
        $account = $site->account;
        $siblingSites = $account !== null ? max(0, $account->sites()->count() - 1) : 0;

        if (! $this->confirmDelete($site, $counts, count($published), $purgeWp, $withAccount, $siblingSites)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->report($site, $counts, $published, [], [], $purgeWp, false, $account, $siblingSites, true);

            return self::SUCCESS;
        }

        $result = $deleter->delete($site, $purgeWp, $withAccount);

        $this->report($site, $counts, $published, $result['wp_deleted'], $result['wp_failed'], $purgeWp, $result['account_deleted'], $account, $siblingSites, false);

        return self::SUCCESS;
    }

    /**
     * Resolve by id first; then by brand_name when it is UNIQUE. An ambiguous brand name (the exact
     * duplicate case this command exists for) is refused with the candidate ids listed, so the caller
     * passes the precise ULID and can't delete the wrong tenant.
     */
    private function resolveSite(string $arg): ?Site
    {
        $byId = Site::query()->find($arg);
        if ($byId !== null) {
            return $byId;
        }

        $matches = Site::query()->where('brand_name', $arg)->get();
        if ($matches->isEmpty()) {
            $this->error("Site not found: {$arg} (pass a Site id or exact brand_name).");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("Ambiguous brand_name '{$arg}' — {$matches->count()} sites match. Pass the exact Site id:");
            foreach ($matches as $match) {
                $this->line("  • {$match->id}  (status={$match->status->value}, domain=".($match->domain_url ?? '—').')');
            }

            return null;
        }

        return $matches->first();
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function confirmDelete(Site $site, array $counts, int $publishedCount, bool $purgeWp, bool $withAccount, int $siblingSites): bool
    {
        if ($this->option('force') || $this->option('dry-run')) {
            return true;
        }

        $this->warn("This PERMANENTLY DELETES '{$site->brand_name}' ({$site->id}) and ALL its data:");
        $this->line("  {$counts['pages']} pages · {$counts['posts']} posts · {$counts['silos']} silos · {$counts['markets']} markets · {$counts['services']} services · {$counts['keywords']} keywords · connection · brand · wizard");
        $this->line('  WordPress: '.($purgeWp ? "force-delete {$publishedCount} published page(s)" : 'left untouched'));
        if ($withAccount) {
            $this->line('  Account: '.($siblingSites === 0 ? 'DELETED (no other sites)' : "kept ({$siblingSites} other site(s))"));
        }

        return $this->confirm('Delete this tenant? This cannot be undone.');
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array{wp_post_id: int, slug: string, type: string}>  $published
     * @param  list<string>  $wpDeleted
     * @param  list<string>  $wpFailed
     */
    private function report(Site $site, array $counts, array $published, array $wpDeleted, array $wpFailed, bool $purgeWp, bool $accountDeleted, ?Account $account, int $siblingSites, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry-run] would delete' : 'Deleted';
        $wiped = "{$counts['pages']} pages · {$counts['posts']} posts · {$counts['silos']} silos · {$counts['markets']} markets · {$counts['services']} services · {$counts['keywords']} keywords";

        $this->newLine();
        $this->info("{$prefix} '{$site->brand_name}' ({$site->id}) and all its data.");
        $this->line("  removed: {$wiped} · connection · brand · wizard");

        if ($purgeWp) {
            if ($dryRun) {
                $this->line('  WP pages to force-delete: '.(count($published) > 0 ? implode(', ', array_column($published, 'slug')) : 'none'));
            } else {
                if ($wpDeleted !== []) {
                    $this->line('  force-deleted '.count($wpDeleted).' WP page(s): '.implode(', ', $wpDeleted));
                }
                if ($wpFailed !== []) {
                    $this->warn('  ⚠ '.count($wpFailed).' WP page(s) NOT deleted: '.implode(', ', $wpFailed));
                }
            }
        } else {
            $this->line('  WordPress: left untouched'.($published !== [] ? ' ('.count($published).' published page(s) remain on WP — pass --purge-wordpress to remove)' : ''));
        }

        if ($account !== null) {
            if ($accountDeleted) {
                $this->line("  Account '{$account->name}' ({$account->id}): deleted (had no other sites).");
            } else {
                $this->line("  Account '{$account->name}' ({$account->id}): kept".($siblingSites > 0 ? " ({$siblingSites} other site(s))" : ' (now has no sites — pass --with-account to remove)').'.');
            }
        }
    }
}
