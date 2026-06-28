<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\SiteStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Account;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Permanently DELETE a tenant (Site) and all of its data — the destructive sibling of
 * {@see ResetTenantCommand} (which only rewinds onboarding and keeps the site row). Use when a
 * duplicate/abandoned tenant must be removed entirely (e.g. re-running the build created a second
 * instance of the same brand).
 *
 * Mechanics:
 *  - Every `site_id` foreign key is `cascadeOnDelete`, so deleting the Site row removes all child
 *    rows (content/silos/services/markets/keywords/connection/brand/wizard/pivots/…) at the DB in
 *    one cascade — guaranteed complete. Three tables carry a RAW `site_id` with no FK
 *    (`arrange_flags`, `content_edits`, `page_configs`); those are cleared explicitly first so no
 *    orphans survive.
 *  - WordPress is left UNTOUCHED by default. A duplicate tenant usually points at the SAME WP
 *    instance as the original, so force-deleting "its" pages would wipe the real site's pages.
 *    `--purge-wordpress` opts into force-deleting this site's published pages (only when you know the
 *    WP instance is exclusively this tenant's).
 *
 * Guards: refuses a `live` (client-handed-over) tenant; refuses an ambiguous brand-name match
 * (lists the candidate ids so you pass the exact ULID); a named confirmation (`--force` to skip).
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

    public function handle(WordpressClientFactory $wordpress): int
    {
        $arg = (string) $this->argument('site');
        $site = $this->resolveSite($arg);
        if ($site === null) {
            return self::FAILURE; // resolveSite already reported why (not found / ambiguous)
        }

        // A live (client-handed-over) tenant is production — never delete it from this tool.
        if ($site->status === SiteStatus::Live) {
            $this->error("Refusing to delete '{$site->brand_name}' ({$site->id}) — it is LIVE (handed to a client).");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $purgeWp = (bool) $this->option('purge-wordpress');
        $withAccount = (bool) $this->option('with-account');

        $published = $this->publishedPages($site->id);
        $counts = $this->counts($site->id);
        $account = $site->account;
        $siblingSites = $account !== null ? max(0, $account->sites()->count() - 1) : 0;

        if (! $this->confirmDelete($site, $counts, count($published), $purgeWp, $withAccount, $siblingSites, $dryRun)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        // WP cleanup FIRST (read post ids from Content before the cascade), only when opted in.
        $wpDeleted = [];
        $wpFailed = [];
        if (! $dryRun && $purgeWp && $published !== []) {
            $client = null;
            try {
                $client = $wordpress->forSite($site);
            } catch (Throwable $e) {
                $this->warn('No usable WordPress connection — skipping WP cleanup ('.$e->getMessage().').');
            }

            foreach ($published as $page) {
                if ($client === null) {
                    $wpFailed[] = $page['slug'];

                    continue;
                }
                try {
                    $ok = $client->forceDeletePost($page['type'], $page['wp_post_id']);
                    $ok ? $wpDeleted[] = $page['slug'] : $wpFailed[] = $page['slug'];
                } catch (Throwable $e) {
                    $wpFailed[] = $page['slug'];
                }
            }
        }

        $accountDeleted = false;
        if (! $dryRun) {
            DB::transaction(function () use ($site, $account, $withAccount, $siblingSites, &$accountDeleted): void {
                // Raw site_id columns (no FK → no cascade) — clear before the site delete.
                foreach (['arrange_flags', 'content_edits', 'page_configs'] as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->where('site_id', $site->id)->delete();
                    }
                }

                // The cascade does the rest: every site_id FK is cascadeOnDelete.
                $site->delete();

                if ($withAccount && $account !== null && $siblingSites === 0) {
                    $account->delete();
                    $accountDeleted = true;
                }
            });
        }

        $this->report($site, $counts, $published, $wpDeleted, $wpFailed, $purgeWp, $accountDeleted, $account, $siblingSites, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Launchpad-published pages/posts (those carrying a WP post id), read BEFORE the delete.
     *
     * @return list<array{wp_post_id: int, slug: string, type: 'pages'|'posts'}>
     */
    private function publishedPages(string $siteId): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('wp_post_id')
            ->get(['wp_post_id', 'slug', 'kind'])
            ->map(fn (Content $c): array => [
                'wp_post_id' => (int) $c->wp_post_id,
                'slug' => '/'.ltrim((string) $c->slug, '/'),
                'type' => $c->kind === ContentKind::Page ? 'pages' : 'posts',
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function counts(string $siteId): array
    {
        $forSite = fn (string $class) => $class::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId);

        return [
            'pages' => (clone $forSite(Content::class))->where('kind', ContentKind::Page->value)->count(),
            'posts' => (clone $forSite(Content::class))->where('kind', ContentKind::Post->value)->count(),
            'silos' => $forSite(Silo::class)->count(),
            'markets' => $forSite(Market::class)->count(),
            'services' => $forSite(Service::class)->count(),
            'keywords' => $forSite(Keyword::class)->count(),
        ];
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
    private function confirmDelete(Site $site, array $counts, int $publishedCount, bool $purgeWp, bool $withAccount, int $siblingSites, bool $dryRun): bool
    {
        if ($this->option('force') || $dryRun) {
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
