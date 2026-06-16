<?php

namespace App\Interview\Expansion;

/**
 * Maps a model-supplied token to the canonical backed-enum value form: lowercased,
 * trimmed, hyphens → underscores (so "own-page" and "Own_Page" both resolve to
 * "own_page"). Tolerant of the small spelling drift a model introduces.
 */
final class EnumNormalizer
{
    public static function normalize(mixed $value): string
    {
        return str_replace('-', '_', strtolower(trim((string) $value)));
    }
}
