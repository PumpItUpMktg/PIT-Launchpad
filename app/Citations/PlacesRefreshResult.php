<?php

namespace App\Citations;

/**
 * The outcome of a {@see PlacesRefresher} pass for one location. `no_place_id` = the location isn't Places-backed
 * (nothing to refresh against); `not_found` = Google no longer resolves that place id; `completed` = the refresh
 * ran, with `fields` naming the Location columns that changed and `nap` the downstream NAP sync result.
 */
final class PlacesRefreshResult
{
    /**
     * @param  list<string>  $fields  Location columns updated from the fresh GBP data
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $fields = [],
        public readonly ?NapHydrationResult $nap = null,
    ) {}

    public function completed(): bool
    {
        return $this->outcome === 'completed';
    }

    /** The GBP data actually moved (Location fields changed). */
    public function refreshed(): bool
    {
        return $this->outcome === 'completed' && $this->fields !== [];
    }

    public static function noPlaceId(): self
    {
        return new self('no_place_id');
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    /** @param list<string> $fields */
    public static function ran(array $fields, NapHydrationResult $nap): self
    {
        return new self('completed', fields: $fields, nap: $nap);
    }
}
