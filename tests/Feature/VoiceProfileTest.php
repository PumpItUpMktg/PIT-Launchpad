<?php

use App\Models\Site;
use App\Models\VoiceProfile;
use Illuminate\Database\QueryException;

test('only one active voice profile is allowed per site', function () {
    $site = Site::factory()->create();

    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 1]);

    expect(fn () => VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 2]))
        ->toThrow(QueryException::class);
});

test('different sites may each have an active voice profile', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    VoiceProfile::factory()->active()->create(['site_id' => $siteA->id, 'version' => 1]);
    VoiceProfile::factory()->active()->create(['site_id' => $siteB->id, 'version' => 1]);

    expect(VoiceProfile::withoutGlobalScopes()->where('status', 'active')->count())->toBe(2);
});

test('a site may keep several non-active voice profile versions', function () {
    $site = Site::factory()->create();

    VoiceProfile::factory()->create(['site_id' => $site->id, 'version' => 1]);
    VoiceProfile::factory()->create(['site_id' => $site->id, 'version' => 2]);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 3]);

    expect(VoiceProfile::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(3);
});
