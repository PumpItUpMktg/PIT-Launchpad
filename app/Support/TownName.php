<?php

namespace App\Support;

use App\Locations\LocationLandingFactory;
use App\Models\CoverageArea;

/**
 * The canonical town-name key used to join a bare town name (a {@see CoverageArea} name,
 * "{City}") to its location page, which is titled "{City}, {ST}" ({@see LocationLandingFactory}).
 * Stripping a trailing ", ST" before lower-casing makes the two sides agree — a divergence here previously
 * pointed every "Areas we serve" town link at the Areas page. Single-sourced so consumers can't drift.
 */
final class TownName
{
    public static function key(string $name): string
    {
        $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($name)));

        return mb_strtolower($name);
    }
}
