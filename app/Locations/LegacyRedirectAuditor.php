<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audits (and optionally repairs) a tenant's LEGACY location redirects — the bare-town URLs a migrated
 * WordPress site still has indexed (`/hoboken/`, `/hackettstown/`, `/trooper/`) that must 301 to the
 * canonical location page (`/hoboken-nj`). Mirrors the already-correct service-tree pattern
 * (`/services/basement-waterproofing/` → `/basement-waterproofing`) for the location layer.
 *
 * The companion plugin already applies redirects from the control-plane {@see Redirect} table on
 * `template_redirect` (priority 0), keyed on the normalized `from_url` — so a pushed row OVERRIDES whatever
 * the live site currently serves for that path (e.g. the stale `/hoboken/` → blog-post rule). This produces
 * the derivable inventory FIRST (preview); the FULL indexed inventory still needs a prod crawl / GSC.
 *
 * `audit($site, apply: false)` computes the plan and touches nothing; `apply: true` upserts the rows in one
 * transaction. It NEVER shadows a live page (a legacy slug that is itself a published page slug is skipped).
 */
class LegacyRedirectAuditor
{
    /**
     * @return array{
     *   create: list<array{from: string, to: string}>,
     *   fix: list<array{from: string, to: string, was: string}>,
     *   ok: list<array{from: string, to: string}>,
     *   skipped_live: list<string>,
     * }
     */
    public function audit(Site $site, bool $apply): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('slug')
            ->get(['id', 'title', 'slug']);

        // Every published page slug — so a legacy redirect never shadows a live page.
        $liveSlugs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('slug')
            ->pluck('slug')
            ->map(fn ($s): string => $this->path((string) $s))
            ->filter()->unique()->all();

        $existing = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->keyBy(fn (Redirect $r): string => $this->path((string) $r->from_url));

        $create = [];
        $fix = [];
        $ok = [];
        $skippedLive = [];
        $toApply = [];

        foreach ($pages as $page) {
            $canonical = $this->path((string) $page->slug);
            $town = $this->townName((string) $page->title);
            $legacy = $this->path(Str::slug($town));

            if ($legacy === '' || $canonical === '' || $legacy === $canonical) {
                continue; // no distinct bare-town legacy form
            }
            if (in_array($legacy, $liveSlugs, true)) {
                $skippedLive[] = $legacy; // a real page lives here — never redirect it

                continue;
            }

            $current = $existing->get($legacy);
            if ($current === null) {
                $create[] = ['from' => $legacy, 'to' => $canonical];
                $toApply[] = ['from' => $legacy, 'to' => $canonical];
            } elseif ($this->path((string) $current->to_url) !== $canonical) {
                $fix[] = ['from' => $legacy, 'to' => $canonical, 'was' => (string) $current->to_url];
                $toApply[] = ['from' => $legacy, 'to' => $canonical];
            } else {
                $ok[] = ['from' => $legacy, 'to' => $canonical];
            }
        }

        if ($apply && $toApply !== []) {
            DB::transaction(function () use ($site, $toApply): void {
                foreach ($toApply as $row) {
                    Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                        ['site_id' => $site->id, 'from_url' => $row['from']],
                        ['to_url' => $row['to'], 'code' => 301, 'status' => 'active', 'source' => RedirectSource::Migration->value],
                    );
                }
            });
        }

        return ['create' => $create, 'fix' => $fix, 'ok' => $ok, 'skipped_live' => array_values(array_unique($skippedLive))];
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
