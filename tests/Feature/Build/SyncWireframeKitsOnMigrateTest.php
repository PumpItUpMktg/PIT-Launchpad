<?php

use App\Listeners\SyncWireframeKitsOnMigrate;
use App\Models\WireframeKit;

it('refreshes a stale library kit back to its current JSON schema (the deploy path)', function () {
    // Simulate the production bug: a kit row seeded once (by the old migration) whose slot_schema is
    // now stale relative to the JSON on disk — the state that makes pages draft/preview thin.
    $kit = WireframeKit::where('name', 'service-page')->whereNull('site_id')->firstOrFail();
    $kit->forceFill(['slot_schema' => ['name' => 'service-page', 'version' => 1, 'page_type' => 'service', 'slots' => [
        ['key' => 'hero_problem', 'label' => 'Hero Problem', 'content_type' => 'heading', 'role' => 'hero_problem', 'source' => 'generated', 'required' => true],
    ]]])->save();
    expect(count($kit->fresh()->schema()->slots))->toBe(1);

    (new SyncWireframeKitsOnMigrate)->handle((object) ['method' => 'up']);

    // The kit is back to the full current slot set from the JSON — proving the deploy-time sync ran.
    expect(count($kit->fresh()->schema()->slots))->toBeGreaterThan(10);
});

it('is idempotent — a second sync does not duplicate or change the kit count', function () {
    (new SyncWireframeKitsOnMigrate)->handle((object) ['method' => 'up']);
    $count = WireframeKit::count();

    (new SyncWireframeKitsOnMigrate)->handle((object) ['method' => 'up']);
    expect(WireframeKit::count())->toBe($count);
});

it('swallows a kit-sync failure so it can never abort a deploy migration', function () {
    (new SyncWireframeKitsOnMigrate)->handle((object) ['method' => 'up']);
})->throwsNoExceptions();
