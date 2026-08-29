<?php

use App\Models\Directory;
use Database\Seeders\DirectorySeeder;

test('the seeder loads the top-weight directories and is idempotent', function (): void {
    (new DirectorySeeder)->run();
    $firstCount = Directory::query()->count();

    expect($firstCount)->toBeGreaterThanOrEqual(15)
        ->and(Directory::query()->where('domain', 'yelp.com')->exists())->toBeTrue()
        ->and(Directory::query()->where('domain', 'google.com')->exists())->toBeTrue();

    $yelp = Directory::query()->where('domain', 'yelp.com')->first();
    expect($yelp->name)->toBe('Yelp')->and($yelp->authority_tier)->toBe(5);

    // Re-running does not duplicate.
    (new DirectorySeeder)->run();
    expect(Directory::query()->count())->toBe($firstCount);
});

test('the platform listings are flagged as requiring client action', function (): void {
    (new DirectorySeeder)->run();

    expect(Directory::query()->where('domain', 'google.com')->first()->requires_client_action)->toBeTrue()
        ->and(Directory::query()->where('domain', 'yelp.com')->first()->requires_client_action)->toBeFalse();
});
