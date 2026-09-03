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
        return mb_strtolower(self::display($name));
    }

    /** The bare town name with its original case (a trailing ", ST" stripped) — for a label or a Review.town match. */
    public static function display(string $name): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($name)));
    }
}
