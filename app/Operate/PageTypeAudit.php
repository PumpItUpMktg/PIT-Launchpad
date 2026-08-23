<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\SiloCreator\PillarFactory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Audits a site's pages for page_type misclassification — the "why is this service page in Core Pages?"
 * bug. The console groups published pages by page_type (Core = Home/Utility, Services = Hub/Service/
 * Pillar/Cluster, Locations = Location), so a service page carrying page_type=Utility shows in Core.
 *
 * A page is flagged `misfiled_core` when it sits in the Core bucket (Home/Utility) yet carries service-
 * structure signals (a silo, a primary service, or a target keyword) — a real standard page
 * (about/contact/faq…) has none of those. `invisible` flags a null page_type (shows on no board at all).
 * repair() re-points the flagged rows to their true type (Hub for a silo pillar, else Service), clears any
 * stray standard_type, and re-resolves the wireframe kit. Read-only until repair().
 */
class PageTypeAudit
{
    /**
     * @return array{rows: list<array<string, mixed>>, flagged: int, invisible: int}
     */
    public function audit(Site $site): array
    {
        $pillars = $this->pillarContentIds($site);

        $rows = $this->pages($site)->map(fn (Content $c): array => [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'status' => $c->status->value,
            'page_type' => $c->page_type?->value,
            'standard_type' => $c->standard_type?->value,
            'has_silo' => $c->silo_id !== null,
            'has_service' => $c->primary_service_id !== null,
            'has_keyword' => $c->target_keyword_id !== null,
            'flag' => $this->flag($c),
            'suggested' => $this->flag($c) === 'misfiled_core' ? $this->suggestedType($c, $pillars)->value : null,
        ])->all();

        return [
            'rows' => $rows,
            'flagged' => count(array_filter($rows, fn (array $r): bool => $r['flag'] === 'misfiled_core')),
            'invisible' => count(array_filter($rows, fn (array $r): bool => $r['flag'] === 'invisible')),
        ];
    }

    /**
     * Re-point every misfiled_core page to its true type. Durable: the materializer skips already-linked
     * rows, so a later PlanSync/materialize will not reassert Utility.
     *
     * @return array{fixed: int, details: list<array{id: string, title: string, from: ?string, to: string}>}
     */
    public function repair(Site $site): array
    {
        $pillars = $this->pillarContentIds($site);
        $fixed = 0;
        $details = [];

        foreach ($this->pages($site) as $c) {
            if ($this->flag($c) !== 'misfiled_core') {
                continue;
            }

            $from = $c->page_type?->value;
            $to = $this->suggestedType($c, $pillars);

            $attrs = ['page_type' => $to, 'standard_type' => null];
            $kit = PillarFactory::resolveKit($to, $site->id);
            if ($kit !== null) {
                $attrs['wireframe_kit_id'] = $kit->id;
                $attrs['wireframe_kit_version'] = $kit->version;
            }

            $c->forceFill($attrs)->save();
            $fixed++;
            $details[] = ['id' => (string) $c->id, 'title' => (string) $c->title, 'from' => $from, 'to' => $to->value];
        }

        return ['fixed' => $fixed, 'details' => $details];
    }

    private function flag(Content $c): string
    {
        if ($c->page_type === null) {
            return 'invisible';
        }

        // Service signals are the definitive tell: a genuine standard page (about/contact/faq…) never
        // carries a silo, a primary service, or a target keyword. A stray standard_type does NOT exempt
        // the row — a service offering mis-materialized as Standard would have one too (repair clears it).
        $inCore = in_array($c->page_type, [PageType::Home, PageType::Utility], true);
        $looksLikeService = $c->silo_id !== null || $c->primary_service_id !== null || $c->target_keyword_id !== null;

        if ($inCore && $looksLikeService) {
            return 'misfiled_core';
        }

        return 'ok';
    }

    /** A page that IS a silo's pillar renders as a Hub; every other misfiled service page is a Service spoke. */
    private function suggestedType(Content $c, array $pillars): PageType
    {
        return isset($pillars[(string) $c->id]) ? PageType::Hub : PageType::Service;
    }

    /** @return array<string, true> */
    private function pillarContentIds(Site $site): array
    {
        $ids = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('pillar_content_id')
            ->pluck('pillar_content_id')
            ->all();

        return array_fill_keys(array_map('strval', $ids), true);
    }

    /** @return Collection<int, Content> */
    private function pages(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->orderBy('page_type')
            ->orderBy('title')
            ->get();
    }
}
