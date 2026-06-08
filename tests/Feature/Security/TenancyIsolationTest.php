<?php

use App\Models\Connection;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(function () {
    CurrentSite::clear();
});

test('tenant A cannot read tenant B rows across the tenant-scoped models', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    $bConnection = Connection::factory()->create(['site_id' => $b->id, 'provider' => 'gbp']);
    $bContent = Content::factory()->create(['site_id' => $b->id]);
    $bSilo = Silo::factory()->create(['site_id' => $b->id]);
    $bMedia = MediaAsset::factory()->create(['site_id' => $b->id]);

    CurrentSite::set($a->id);

    // Direct lookups of another tenant's rows return nothing while scoped.
    expect(Connection::find($bConnection->id))->toBeNull()
        ->and(Content::find($bContent->id))->toBeNull()
        ->and(Silo::find($bSilo->id))->toBeNull()
        ->and(MediaAsset::find($bMedia->id))->toBeNull();

    // And they are absent from any scoped aggregate.
    expect(Connection::count())->toBe(0)
        ->and(Content::count())->toBe(0)
        ->and(Silo::count())->toBe(0)
        ->and(MediaAsset::count())->toBe(0);

    // The rows do exist — only the tenant boundary hides them.
    expect(Connection::withoutGlobalScopes()->find($bConnection->id))->not->toBeNull()
        ->and(MediaAsset::withoutGlobalScopes()->find($bMedia->id))->not->toBeNull();
});

test('a tenant cannot reach another tenant credentials by any scoped query', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    Connection::factory()->create([
        'site_id' => $b->id,
        'provider' => 'wp_app_password',
        'credentials' => ['password' => 'tenant-b-only'],
    ]);

    CurrentSite::set($a->id);

    expect(Connection::where('provider', 'wp_app_password')->get())->toHaveCount(0)
        ->and(Connection::pluck('id'))->toHaveCount(0);
});

test('each tenant sees only its own connections', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    Connection::factory()->count(2)->sequence(
        ['provider' => 'gbp'],
        ['provider' => 'ga4'],
    )->create(['site_id' => $a->id]);
    Connection::factory()->create(['site_id' => $b->id, 'provider' => 'gbp']);

    CurrentSite::set($a->id);
    expect(Connection::count())->toBe(2);

    CurrentSite::set($b->id);
    expect(Connection::count())->toBe(1);
});
