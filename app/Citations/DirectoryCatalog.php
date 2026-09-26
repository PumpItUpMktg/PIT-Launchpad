<?php

namespace App\Citations;

use App\Models\Directory;
use Database\Seeders\DirectorySeeder;

/**
 * The global directory catalog's floor: a real site must never scan against an EMPTY catalog (every location
 * would show 0 eligible directories and "no citations"). When no active directory exists, the seeded national
 * set is loaded on demand — the same rows the board's "Seed directory catalog" action writes. Idempotent.
 */
final class DirectoryCatalog
{
    /** Seed the national catalog if there is nothing active to scan against. Returns true when it seeded. */
    public static function ensureSeeded(): bool
    {
        if (Directory::query()->where('is_active', true)->exists()) {
            return false;
        }

        (new DirectorySeeder)->run();

        return true;
    }
}
