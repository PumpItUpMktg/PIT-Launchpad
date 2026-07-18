<?php

namespace App\Console\Commands;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Locations\LocationLandingFactory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
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

    public function handle(PageGenerator $generator): int
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->argument('location'));
        if ($location === null) {
            $this->error('Location not found.');

            return self::FAILURE;
        }

        ['city' => $city] = $location->cityState();
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

        $page = app(LocationLandingFactory::class)->findOrCreate($location);
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
}
