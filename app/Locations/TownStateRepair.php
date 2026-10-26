<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Census\TigerwebGazetteer;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\TownName;
use Illuminate\Support\Collection;

/**
 * A town page whose stored title names the wrong STATE for the town it is anchored to (§ Locations) — the
 * legacy of pages titled from a bare served-town name before geo anchoring, when the state defaulted to the
 * tenant's home state ("Middletown, NJ" for the Bucks County Middletown). The anchored town is the truth:
 * its Census id says the state. The repair retitles the page "{Town}, {ST}", rewrites the "{Town}, {old}"
 * wording the drafter copied into the H1 and copy, re-nests the slug (which records the 301 from the old
 * path), corrects the coverage row's state when it disagrees with its own id, and re-pushes. Report-only
 * unless executed; a page with no anchored town is never touched.
 */
final class TownStateRepair
{
    public function __construct(private readonly LocationNesting $nesting) {}

    /**
     * @return list<array{id: string, slug: string, title: string, town: string, stored_state: string, state: string, new_title: string}>
     */
    public function report(Site $site): array
    {
        $out = [];
        foreach ($this->townPages($site) as $page) {
            $state = $this->authoritativeState($page);
            $stored = self::stateInTitle((string) $page->title);
            if ($state === null || $stored === null || $stored === $state) {
                continue;
            }
            $town = TownName::display((string) $page->title);
            $out[] = [
                'id' => (string) $page->id,
                'slug' => (string) $page->slug,
                'title' => (string) $page->title,
                'town' => $town,
                'stored_state' => $stored,
                'state' => $state,
                'new_title' => $town.', '.$state,
            ];
        }

        return $out;
    }

    /**
     * Retitle, rewrite the wording, fix the coverage row, re-nest (301s recorded), re-push. Returns the rows repaired.
     *
     * @return list<array{id: string, old_slug: string, new_slug: string, title: string, new_title: string}>
     */
    public function execute(Site $site): array
    {
        $rows = $this->report($site);
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $pages = Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->get()->keyBy('id');
        $oldSlugs = [];
        foreach ($rows as $r) {
            $page = $pages->get($r['id']);
            if ($page === null) {
                continue;
            }
            $oldSlugs[$r['id']] = (string) $page->slug;
            $old = $r['town'].', '.$r['stored_state'];
            $new = $r['new_title'];
            $page->title = $new;
            $page->slot_payload = self::rewrite($page->slot_payload, $old, $new);
            $page->meta = self::rewrite($page->meta, $old, $new);
            $page->save();

            // The coverage row's state must agree with its own Census id; correct it if the page's wrong state leaked in.
            CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('geo_id', (string) $page->geo_id)
                ->where('state', '!=', $r['state'])->update(['state' => $r['state']]);
        }

        // Re-nest the site's town pages: the slug follows the corrected title; a changed slug records its 301.
        $this->nesting->nest($site);

        $out = [];
        foreach ($rows as $r) {
            $fresh = Content::withoutGlobalScope(SiteScope::class)->find($r['id']);
            if ($fresh === null) {
                continue;
            }
            PublishContent::dispatch((string) $fresh->id);
            $out[] = ['id' => $r['id'], 'old_slug' => $oldSlugs[$r['id']] ?? '', 'new_slug' => (string) $fresh->slug, 'title' => $r['title'], 'new_title' => $r['new_title']];
        }

        return $out;
    }

    /** The state the page's anchored town is in — from its Census id, the one thing that cannot be mislabelled. */
    private function authoritativeState(Content $page): ?string
    {
        $geo = trim((string) $page->geo_id);
        if (strlen($geo) < 2) {
            return null;
        }

        return TigerwebGazetteer::stateForFips($geo);
    }

    /** The trailing ", ST" of a stored title, upper-case; null when the title carries none. */
    public static function stateInTitle(string $title): ?string
    {
        return preg_match('/,\s*([A-Za-z]{2})\.?\s*$/', trim($title), $m) === 1 ? strtoupper($m[1]) : null;
    }

    /** Replace "{Town}, {old}" with "{Town}, {new}" in every string of a nested payload, case-insensitively on the state. */
    public static function rewrite(mixed $value, string $old, string $new): mixed
    {
        if (is_string($value)) {
            return preg_replace('/'.preg_quote($old, '/').'/i', $new, $value) ?? $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::rewrite($v, $old, $new);
            }
        }

        return $value;
    }

    /** @return Collection<int, Content> */
    private function townPages(Site $site)
    {
        return Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNull('location_id')->whereNotNull('parent_location_id')->whereNotNull('geo_id')
            ->orderBy('slug')->get();
    }
}
