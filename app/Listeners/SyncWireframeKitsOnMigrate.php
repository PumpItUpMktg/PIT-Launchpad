<?php

namespace App\Listeners;

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the library wireframe kits (the `wireframe_kits` rows) in step with their JSON source on every
 * deploy — without a manual `launchpad:sync-kits` run or a dashboard deploy-command edit.
 *
 * Kits are DB-seeded reference data: a code deploy ships the new kit JSON but does NOT refresh the rows,
 * so a kit rewrite (new slots) silently never reaches prod — pages then draft/preview against the stale
 * schema and read thin. This listener closes that gap by re-running {@see WireframeKitSeeder} whenever
 * migrations run. A deploy always runs `php artisan migrate --force`, which fires EITHER
 * `MigrationsEnded` (something to migrate) OR `NoPendingMigrations` (nothing pending) — so binding both
 * means the kits sync on every deploy regardless of whether that deploy carried a migration.
 *
 * Idempotent (the seeder upserts library-level rows by name+version), so re-running it is safe. Guarded
 * at registration to NOT bind in the test environment (see AppServiceProvider), so the test suite's own
 * migrations never trigger a seed; the handler is still unit-tested directly.
 */
class SyncWireframeKitsOnMigrate
{
    public function handle(object $event): void
    {
        try {
            (new WireframeKitSeeder)->run();
        } catch (Throwable $e) {
            // A kit-sync failure must never abort a deploy's migration step — log and move on.
            Log::warning('Wireframe-kit sync on migrate failed', ['error' => $e->getMessage()]);
        }
    }
}
