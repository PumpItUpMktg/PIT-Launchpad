<?php

namespace App\Console\Commands;

use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collapse a site's nested TOWN child pages into their parent location page (Stage 8.1 — the operator's
 * chosen "collapse" fork over "invest"). Each town child (a Location page nested under a parent, carrying
 * a `parent_location_id` but no `location_id` of its own) is:
 *
 *   1. 301-redirected to its parent location page (equity preserved), and
 *   2. soft-deleted from the control plane (the thin near-duplicate goes away).
 *
 * This is CONTROL-PLANE ONLY. It writes redirect rows + soft-deletes rows; it NEVER pushes to WordPress.
 * After a run, the operator (Eric) pushes the redirects, takes down the child WP posts, and repushes the
 * parents. Because it also removes the defective children wholesale, it supersedes the per-child
 * wrong-state (finding B) / `-2` (C) / self-nested (D) repairs — those pages are deleted, not fixed.
 *
 * Default is a DRY RUN (report only); `--apply` performs the writes in one transaction.
 */
class CollapseTownChildrenCommand extends Command
{
    protected $signature = 'launchpad:collapse-town-children
        {--site= : Site id or brand name (required)}
        {--apply : Write the 301s + soft-delete the children (default: dry-run report only)}';

    protected $description = 'Collapse nested town child pages into their parent location page (301 + remove). Control-plane only — Eric pushes redirects, WP takedown, and repush.';

    public function handle(): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Site not found — pass --site=<id|brand name>.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        // Town children: a Location page nested under a parent (parent_location_id set) that is NOT itself
        // a pinned landing page (a landing page carries its own location_id).
        $children = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->orderBy('parent_location_id')
            ->get(['id', 'title', 'slug', 'parent_location_id', 'wp_post_id']);

        if ($children->isEmpty()) {
            $this->info("No nested town child pages for {$site->brand_name}. Nothing to collapse.");

            return self::SUCCESS;
        }

        // The 301 target: the parent LANDING page per Location id (page_type=location, own location_id set).
        $parents = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->whereNotNull('slug')
            ->get(['id', 'slug', 'location_id'])
            ->keyBy(fn (Content $c): string => (string) $c->location_id);

        /** @var list<array{child: Content, from: string, to: string, parentSlug: string}> $plan */
        $plan = [];
        /** @var list<Content> $orphans */
        $orphans = [];
        foreach ($children as $child) {
            $parent = $parents->get((string) $child->parent_location_id);
            $from = $this->path((string) $child->slug);
            $to = $parent !== null ? $this->path((string) $parent->slug) : '';

            // Skip anything we can't turn into a clean, non-self redirect to a real parent page — those
            // are surfaced as orphans (a child whose parent landing page is missing) so they're never
            // silently deleted without a redirect target.
            if ($to === '' || $from === '' || $from === $to) {
                $orphans[] = $child;

                continue;
            }
            $plan[] = ['child' => $child, 'from' => $from, 'to' => $to, 'parentSlug' => (string) $parent->slug];
        }

        $this->report($site, $plan, $orphans);

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run with --apply to collapse.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $site): void {
            foreach ($plan as $row) {
                Redirect::query()->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $row['from']],
                    ['to_url' => $row['to'], 'code' => 301, 'source' => RedirectSource::Migration, 'status' => 'active'],
                );
                $row['child']->delete(); // soft delete — the row is kept for audit, the page is gone
            }
        });

        $this->newLine();
        $this->info('Collapsed '.count($plan).' town child page(s): 301 written + child soft-deleted.');
        $this->line('NEXT (operator): push the redirects, take down the child WP posts, then repush the parents.');
        if ($orphans !== []) {
            $this->warn(count($orphans).' orphan(s) had no parent landing page and were LEFT untouched (see above).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{child: Content, from: string, to: string, parentSlug: string}>  $plan
     * @param  list<Content>  $orphans
     */
    private function report(Site $site, array $plan, array $orphans): void
    {
        $this->line("Collapse plan for <info>{$site->brand_name}</info>:");

        /** @var array<string, list<string>> $byParent */
        $byParent = [];
        $published = 0;
        foreach ($plan as $row) {
            $byParent[$row['parentSlug']][] = $this->townName((string) $row['child']->title);
            if ($row['child']->wp_post_id !== null) {
                $published++;
            }
        }
        ksort($byParent);

        foreach ($byParent as $parentSlug => $towns) {
            sort($towns);
            $this->line("  /{$parentSlug}  ← ".count($towns).' town(s): '.implode(', ', $towns));
        }

        $this->newLine();
        $this->line('Children to collapse: <info>'.count($plan)."</info> ({$published} live on WP → need takedown)");
        $this->line('Parents receiving redirects: <info>'.count($byParent).'</info>');
        if ($orphans !== []) {
            $this->warn('Orphans (no parent landing page — NOT collapsed): '.count($orphans));
            foreach ($orphans as $o) {
                $this->line('  · '.($o->slug ?? $o->id).' ('.$o->title.')');
            }
        }
    }

    private function resolveSite(): ?Site
    {
        $arg = $this->option('site');
        if (! is_string($arg) || $arg === '') {
            return null;
        }

        return Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
    }

    /** The town label without a trailing ", ST" state suffix ("Hoboken, NJ" → "Hoboken"). */
    private function townName(string $title): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?\s*$/', '', trim($title)));
    }

    /** Leading-slash path with the trailing slash stripped — the companion plugin's redirect-map key form. */
    private function path(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;
        $path = trim($path, '/');

        return $path === '' ? '' : '/'.$path;
    }
}
