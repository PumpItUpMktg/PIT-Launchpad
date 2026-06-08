<?php

use App\Models\Connection;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

test('connection credentials are encrypted at rest', function () {
    $site = Site::factory()->create();

    $connection = Connection::factory()->create([
        'site_id' => $site->id,
        'credentials' => ['token' => 'super-secret-token'],
    ]);

    $raw = DB::table('connections')->where('id', $connection->id)->value('credentials');

    expect($raw)->not->toContain('super-secret-token');

    expect($connection->fresh()->credentials)->toBe(['token' => 'super-secret-token']);
});
