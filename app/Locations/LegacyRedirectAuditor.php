<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
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
 *
 * Two guards, both learned on Sump Pump Gurus' 213-row preview:
 *   - only a PUBLISHED, live page is a target — an unpublished town page produced "/queens → /roslyn-ny/
 *     queens-ny" rows into pages nobody serves;
 *   - a bare slug that several live pages claim (Middletown NJ and PA, Bethlehem PA and NJ, the Bedminster
 *     OFFICE and a Bedminster PA town) is settled only when exactly one claimant is an office landing (the
 *     bare `/bedminster` is the office); otherwise it is AMBIGUOUS — an existing redirect to any claimant is
 *     left alone, a missing one is surfaced, and nothing is written. The last page in the loop used to win.
 */
class LegacyRedirectAuditor
{
    /**
     * @return array{
     *   create: list<array{from: string, to: string}>,
     *   fix: list<array{from: string, to: string, was: string}>,
     *   ok: list<array{from: string, to: string}>,
     *   ambiguous: list<array{from: string, candidates: list<string>, current: string|null}>,
     *   skipped_live: list<string>,
     * }
     */
    public function audit(Site $site, bool $apply): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('slug')
            ->get(['id', 'title', 'slug', 'location_id']);

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
        $ambiguous = [];
        $skippedLive = [];
        $toApply = [];

        // Every live page that claims a bare-town slug, grouped — so a shared name is decided once, not
        // once per page with the last one winning.
        /** @var array<string, list<array{to: string, landing: bool}>> $claims */
        $claims = [];
        foreach ($pages as $page) {
            $canonical = $this->path((string) $page->slug);
            foreach ($this->legacyForms($page) as $legacy) {
                if ($legacy === '' || $canonical === '' || $legacy === $canonical) {
                    continue; // no distinct legacy form
                }
                if (in_array($legacy, $liveSlugs, true)) {
                    $skippedLive[] = $legacy; // a real page lives here — never redirect it

                    continue;
                }
                $claims[$legacy][] = ['to' => $canonical, 'landing' => $page->location_id !== null];
            }
        }

        foreach ($claims as $legacy => $claimants) {
            $current = $existing->get($legacy);
            $currentTo = $current !== null ? $this->path((string) $current->to_url) : null;
            $targets = array_values(array_unique(array_column($claimants, 'to')));

            // One claimant, or exactly one office landing among several: the bare slug is that page's.
            $canonical = count($targets) === 1 ? $targets[0] : null;
            if ($canonical === null) {
                $landings = array_values(array_unique(array_column(array_filter($claimants, fn (array $c): bool => $c['landing']), 'to')));
                $canonical = count($landings) === 1 ? $landings[0] : null;
            }
            if ($canonical === null) {
                // Several towns share the name and none is the office: never guess. An existing redirect to
                // any of them stays; a missing one is surfaced for a hand decision.
                if ($currentTo !== null && in_array($currentTo, $targets, true)) {
                    $ok[] = ['from' => $legacy, 'to' => $currentTo];
                } else {
                    $ambiguous[] = ['from' => $legacy, 'candidates' => $targets, 'current' => $current !== null ? (string) $current->to_url : null];
                }

                continue;
            }

            if ($current === null) {
                $create[] = ['from' => $legacy, 'to' => $canonical];
                $toApply[] = ['from' => $legacy, 'to' => $canonical];
            } elseif ($currentTo !== $canonical) {
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

        return ['create' => $create, 'fix' => $fix, 'ok' => $ok, 'ambiguous' => $ambiguous, 'skipped_live' => array_values(array_unique($skippedLive))];
    }

    /**
     * Write the legacy redirects for ONE just-published location page — create-only (an existing row for
     * the same legacy path, however it got there, is never overwritten here; the audit's repoint logic
     * owns that), never shadowing a live page slug. Called at publish time so a town page nested under its
     * hub carries its old top-level URL from the moment it goes live, without waiting for an audit run.
     * Returns how many rows were created.
     */
    public function forPage(Content $page): int
    {
        if ($page->kind !== ContentKind::Page || $page->page_type !== PageType::Location || $page->status !== ContentStatus::Published) {
            return 0;
        }
        $canonical = $this->path((string) $page->slug);
        if ($canonical === '') {
            return 0;
        }
        $liveSlugs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->whereNotNull('slug')
            ->pluck('slug')
            ->map(fn ($s): string => $this->path((string) $s))
            ->filter()->unique()->all();

        $created = 0;
        foreach (array_unique($this->legacyForms($page)) as $legacy) {
            if ($legacy === '' || $legacy === $canonical || in_array($legacy, $liveSlugs, true)) {
                continue;
            }
            $exists = Redirect::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $page->site_id)->where('from_url', $legacy)->exists();
            if ($exists) {
                continue;
            }
            Redirect::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $page->site_id, 'from_url' => $legacy, 'to_url' => $canonical,
                'code' => 301, 'status' => 'active', 'source' => RedirectSource::Migration->value,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * The legacy top-level URLs a location page may still be indexed at: the bare town slug ("/hoboken"
     * for "Hoboken, NJ"), and — for a page nested under its hub — its own last slug segment at the top
     * level ("/newark-nj" for "/montclair-nj/newark-nj"), which is the URL every town page LIVED at before
     * nesting and the one Google indexed.
     *
     * @return list<string>
     */
    private function legacyForms(Content $page): array
    {
        $forms = [$this->path(Str::slug($this->townName((string) $page->title)))];
        $slug = trim((string) $page->slug, '/');
        if (str_contains($slug, '/')) {
            $forms[] = $this->path((string) substr($slug, (int) strrpos($slug, '/') + 1));
        }

        return array_values(array_filter($forms, fn (string $f): bool => $f !== ''));
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
