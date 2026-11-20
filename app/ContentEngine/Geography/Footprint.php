<?php

namespace App\ContentEngine\Geography;

use App\Enums\PlaceVerdict;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The tenant's service footprint, read from its own records — every physical Location's state, the corporate
 * state, every Market's region and every coverage town's state — plus the region ring around it (the states
 * that share a border, when `launchpad.reactive.geography.ring` is `neighbors`). Two jobs:
 *
 *  - {@see promptLine()} tells the relevance scorer WHERE the brand operates (it was never told before, so it
 *    could not know Omaha was out of market), so it can extract the story's place against a known area;
 *  - {@see classify()} turns the extracted place into a {@see PlaceVerdict} in code — the model names the
 *    place, the footprint decides. An unknown footprint never guesses: everything is Unknown.
 */
final class Footprint
{
    /** Towns listed in the prompt (offices and markets first, then coverage towns) — enough to anchor, not a gazetteer. */
    public const PROMPT_TOWN_CAP = 40;

    /**
     * @param  list<string>  $states  2-letter, upper-case, unique
     * @param  list<string>  $ring  neighbouring states NOT in $states
     * @param  list<string>  $towns  "Town ST" labels, de-duplicated, offices/markets first
     */
    public function __construct(
        public readonly array $states,
        public readonly array $ring,
        public readonly array $towns,
    ) {}

    public static function forSite(Site $site): self
    {
        $states = [];
        $towns = [];
        $add = function (?string $town, ?string $state) use (&$states, &$towns): void {
            $code = UsStates::code($state);
            if ($code !== null) {
                $states[$code] = true;
            }
            $town = trim((string) $town);
            if ($town !== '') {
                $towns[mb_strtolower($town.'|'.($code ?? ''))] = trim($town.' '.($code ?? ''));
            }
        };

        foreach (Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get() as $location) {
            $cs = $location->cityState();
            $add((string) $cs['city'], (string) $cs['state']);
        }
        $add(null, $site->corporate_state);
        foreach (Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['name', 'region']) as $market) {
            $add((string) $market->name, is_string($market->region) ? $market->region : null);
        }
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('distance_miles')->get(['name', 'state']) as $area) {
            $add((string) $area->name, $area->state);
        }

        $stateList = array_keys($states);
        sort($stateList);

        $ring = [];
        if (config('launchpad.reactive.geography.ring', 'neighbors') === 'neighbors') {
            foreach ($stateList as $code) {
                foreach (UsStates::NEIGHBORS[$code] ?? [] as $n) {
                    if (! isset($states[$n])) {
                        $ring[$n] = true;
                    }
                }
            }
        }
        $ringList = array_keys($ring);
        sort($ringList);

        return new self($stateList, $ringList, array_values($towns));
    }

    public function isKnown(): bool
    {
        return $this->states !== [];
    }

    /** The verdict for a story anchored to `$state` (code or name; null = no place named). */
    public function classify(?string $state): PlaceVerdict
    {
        if (! $this->isKnown()) {
            return PlaceVerdict::Unknown;
        }
        $code = UsStates::code($state);
        if ($code === null) {
            return PlaceVerdict::Unknown;
        }
        if (in_array($code, $this->states, true)) {
            return PlaceVerdict::Footprint;
        }
        if (in_array($code, $this->ring, true)) {
            return PlaceVerdict::Region;
        }

        return PlaceVerdict::Outside;
    }

    /** The service-area line for the scorer prompt, or null when the footprint is unknown. */
    public function promptLine(): ?string
    {
        if (! $this->isKnown()) {
            return null;
        }
        $states = implode(', ', array_map(fn (string $c): string => UsStates::name($c).' ('.$c.')', $this->states));
        $line = 'Service area (states): '.$states.'.';
        if ($this->towns !== []) {
            $shown = array_slice($this->towns, 0, self::PROMPT_TOWN_CAP);
            $line .= ' Offices and towns served include: '.implode('; ', $shown).(count($this->towns) > count($shown) ? '; …' : '').'.';
        }
        if ($this->ring !== []) {
            $line .= ' Neighbouring states ('.implode(', ', $this->ring).') are the wider region, not the service area.';
        }

        return $line;
    }
}
