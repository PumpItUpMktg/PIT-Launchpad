<?php

namespace App\Console\Commands;

use App\Build\PageMaterializer;
use App\Build\Permalinks;
use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\SiloCreator\PillarFactory;
use Illuminate\Console\Command;

/**
 * Generate the LOCATION PAGE for a §1 Location — the one rich local landing page per GBP location.
 * Find-or-creates the pinned `kind=page` Content row (page_type=location, `location_id` set, titled
 * "{City}, {ST}", kit-resolved), then drives the SAME synchronous drafting path as
 * launchpad:generate-page (grounding → drafter → KitValidator → render). Idempotent: re-running
 * regenerates the same row, never a duplicate page.
 *
 * Guarded: a location with no city AND no served towns has nothing honest to say — the command
 * fails with the exact fix (geocode the address / add served towns) instead of drafting thin air.
 * Explicit and operator-invoked only, like every generation trigger.
 */
class GenerateLocationCommand extends Command
{
    protected $signature = 'launchpad:generate-location {location : a Location id} {--force-grounding : refetch grounded local facts even if the cache is fresh}';

    protected $description = 'Create (or reuse) and generate the location page for a §1 Location — drafts (Sonnet) + renders (fal) → review queue.';

    public function handle(PageGenerator $generator, Permalinks $permalinks): int
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->argument('location'));
        if ($location === null) {
            $this->error('Location not found.');

            return self::FAILURE;
        }

        ['city' => $city, 'state' => $state] = $location->cityState();
        if ($city === '') {
            $city = trim((string) $location->name);
        }

        $townCount = count(array_filter(
            $location->served_towns ?? [],
            fn (array $t): bool => trim((string) ($t['name'] ?? '')) !== '',
        ));

        // The guard: no city and no served towns ⇒ no honest local page. Fail with the exact fix.
        if ($city === '' && $townCount === 0) {
            $this->error('This location has no city and no served towns — nothing honest to build a local page from.');
            $this->line('Fix: set the location address (so it geocodes to a city) or add served towns on the Location, then re-run.');

            return self::FAILURE;
        }

        if ($this->option('force-grounding')) {
            $location->forceFill(['grounding_cache' => null])->save();
        }

        $page = $this->findOrCreatePage($location, $city, $state, $permalinks);
        $this->line(sprintf('Location page: %s (%s)', $page->title, $page->id));

        try {
            $result = $generator->generate($page);
            $this->info(sprintf("Generated '%s' → %s.", $result->title, $result->status->value));
        } catch (DraftFailedException $e) {
            $this->error(sprintf("Failed '%s' — %s", $page->title ?? $page->id, $e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The Location's page — found by its `location_id` pin (idempotent), else created planned/
     * undrafted with its permalink assigned up front, mirroring {@see PageMaterializer}.
     */
    private function findOrCreatePage(Location $location, string $city, string $state, Permalinks $permalinks): Content
    {
        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $location->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $title = $city !== '' ? ($state !== '' ? "{$city}, {$state}" : $city) : trim((string) $location->name);

        /** @var Site $site */
        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($location->site_id);
        $slug = $permalinks->uniqueSlug($title, $permalinks->takenSlugs($site));
        $kit = PillarFactory::resolveKit(PageType::Location, (string) $location->site_id);

        return Content::create([
            'site_id' => $location->site_id,
            'kind' => ContentKind::Page,
            'page_type' => PageType::Location,
            'status' => ContentStatus::Candidate,
            'title' => $title,
            'slug' => $slug,
            'version' => 1,
            'location_id' => $location->id,
            'wireframe_kit_id' => $kit?->id,
            'wireframe_kit_version' => $kit?->version,
        ]);
    }
}
