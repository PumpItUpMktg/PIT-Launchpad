<?php

namespace App\Build;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\BuildSource;
use App\Jobs\GeneratePage;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Build ONE town's page, on demand, from wherever the operator noticed it was missing.
 *
 * Two surfaces ask for this: the pages board's eligible list, and the rankings map — where a town with no
 * page is exactly the town whose diagnosis says to build one, and clicking through from the map is shorter
 * than remembering its name and finding it on another screen.
 *
 * The town is selected if it was not already (asking to build it IS selecting it — the manifest only carries
 * selected towns), its manifest entry is assembled, that ONE entry is materialized, and the draft is queued
 * when the page can ground. Never "generate everything to reach one".
 */
final class TownPageBuilder
{
    public function __construct(
        private readonly BuildManifestAssembler $assembler,
        private readonly PageMaterializer $materializer,
        private readonly GroundingReadiness $readiness,
    ) {}

    /**
     * @return array{page: Content|null, queued: bool} page null = the town has no plan entry to build
     */
    public function build(Site $site, CoverageArea $town, ?string $actorId = null): array
    {
        if (! $town->page_selected) {
            $town->forceFill(['page_selected' => true])->save();
        }

        $this->assembler->assemble($site);
        $this->materializer->materialize($site, onlyPageKey: (string) $town->id);

        // THIS town's page, by its manifest entry — materializing an entry can bring its location's landing
        // page with it, and "the first page created" is not the same thing as "the page for this town".
        $entry = BuildPage::query()
            ->where('site_id', $site->id)
            ->where('source', BuildSource::Location)
            ->where('page_key', (string) $town->id)
            ->first();
        $page = $entry?->content_id !== null
            ? Content::withoutGlobalScope(SiteScope::class)->find($entry->content_id)
            : null;

        if ($page === null) {
            return ['page' => null, 'queued' => false];
        }

        // An honest gate: a page that cannot ground yet is created and left in the work lane, never queued
        // to produce an empty draft.
        if (! $this->readiness->ready($page)) {
            return ['page' => $page, 'queued' => false];
        }

        GeneratePage::enqueue($page, actorId: $actorId);

        return ['page' => $page, 'queued' => true];
    }
}
