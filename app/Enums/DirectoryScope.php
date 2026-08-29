<?php

namespace App\Enums;

/**
 * The geographic reach of a citation directory. National directories apply to every location; state/county/
 * town directories are geo-scoped and (per the citation module's multi-location rules) owned by exactly ONE
 * location — the one that owns that geography via the town→location assignment.
 */
enum DirectoryScope: string
{
    case National = 'national';
    case State = 'state';
    case County = 'county';
    case Town = 'town';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c): array => [$c->value => $c->label()])->all();
    }

    public function isGeoScoped(): bool
    {
        return $this !== self::National;
    }
}
