<?php

namespace App\Operator;

use App\Console\Commands\DeleteSiteCommand;
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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The canonical "permanently delete a tenant" operation, shared by the
 * {@see DeleteSiteCommand} CLI and the operator portfolio's row action.
 *
 * Mechanics: every `site_id` foreign key is `cascadeOnDelete`, so deleting the Site row removes all
 * child rows in one DB cascade. Three tables carry a RAW `site_id` with no FK (`arrange_flags`,
 * `content_edits`, `page_configs`) — cleared explicitly first so nothing orphans. WordPress is left
 * untouched unless `purgeWordpress` is set (a duplicate tenant usually shares the original's WP
 * instance, so force-deleting "its" pages would wipe the real site's pages).
 */
final class SiteDeleter
{
    /** The three site-scoped tables whose `site_id` has no cascade FK — cleared by hand. */
    private const RAW_SITE_TABLES = ['arrange_flags', 'content_edits', 'page_configs'];

    public function __construct(private readonly WordpressClientFactory $wordpress) {}

    public function isLive(Site $site): bool
    {
        return $site->status === SiteStatus::Live;
    }

    /**
     * Delete the site and all its data. Returns the WP-cleanup outcome and whether the owning
     * account was removed. WP cleanup (when requested) runs FIRST — the Content rows hold the WP
     * post ids, which the cascade is about to destroy.
     *
     * @return array{wp_deleted: list<string>, wp_failed: list<string>, account: Account|null, account_deleted: bool, sibling_sites: int}
     */
    public function delete(Site $site, bool $purgeWordpress = false, bool $withAccount = false): array
    {
        $account = $site->account;
        $siblingSites = $account !== null ? max(0, $account->sites()->count() - 1) : 0;

        [$wpDeleted, $wpFailed] = $purgeWordpress ? $this->purgeWordpress($site) : [[], []];

        $accountDeleted = false;
        DB::transaction(function () use ($site, $account, $withAccount, $siblingSites, &$accountDeleted): void {
            foreach (self::RAW_SITE_TABLES as $table) {
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

        return [
            'wp_deleted' => $wpDeleted,
            'wp_failed' => $wpFailed,
            'account' => $account,
            'account_deleted' => $accountDeleted,
            'sibling_sites' => $siblingSites,
        ];
    }

    /**
     * Force-delete this site's published pages on WordPress (read from Content before the cascade).
     *
     * @return array{0: list<string>, 1: list<string>} [deleted slugs, failed slugs]
     */
    private function purgeWordpress(Site $site): array
    {
        $published = $this->publishedPages($site);
        if ($published === []) {
            return [[], []];
        }

        $client = null;
        try {
            $client = $this->wordpress->forSite($site);
        } catch (Throwable) {
            // No usable connection — every page is reported as failed (slug may still be reserved).
        }

        $deleted = [];
        $failed = [];
        foreach ($published as $page) {
            if ($client === null) {
                $failed[] = $page['slug'];

                continue;
            }
            try {
                $ok = $client->forceDeletePost($page['type'], $page['wp_post_id']);
                $ok ? $deleted[] = $page['slug'] : $failed[] = $page['slug'];
            } catch (Throwable) {
                $failed[] = $page['slug'];
            }
        }

        return [$deleted, $failed];
    }

    /**
     * Launchpad-published pages/posts (those carrying a WP post id).
     *
     * @return list<array{wp_post_id: int, slug: string, type: 'pages'|'posts'}>
     */
    public function publishedPages(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
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
    public function counts(Site $site): array
    {
        $forSite = fn (string $class) => $class::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);

        return [
            'pages' => (clone $forSite(Content::class))->where('kind', ContentKind::Page->value)->count(),
            'posts' => (clone $forSite(Content::class))->where('kind', ContentKind::Post->value)->count(),
            'silos' => $forSite(Silo::class)->count(),
            'markets' => $forSite(Market::class)->count(),
            'services' => $forSite(Service::class)->count(),
            'keywords' => $forSite(Keyword::class)->count(),
        ];
    }
}
