<?php

use App\Models\Account;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Models\VoiceProfile;
use Database\Seeders\DemoSeeder;

test('the demo seeder produces a coherent fixture', function () {
    $this->seed(DemoSeeder::class);

    expect(Account::count())->toBe(1)
        ->and(Site::count())->toBe(1);

    $site = Site::first();

    expect($site->branding)->not->toBeNull()
        ->and($site->conversionConfig)->not->toBeNull();

    // Two silos: a service pillar with a nested topical silo.
    $pillar = Silo::withoutGlobalScopes()->whereNotNull('pillar_content_id')->first();
    expect($pillar)->not->toBeNull()
        ->and($pillar->children)->toHaveCount(1);

    // One page and one post, both wired to the silo spine.
    expect(Content::withoutGlobalScopes()->where('kind', 'page')->count())->toBe(1)
        ->and(Content::withoutGlobalScopes()->where('kind', 'post')->count())->toBe(1);

    // Exactly one active voice profile.
    expect(VoiceProfile::withoutGlobalScopes()->where('status', 'active')->count())->toBe(1);
});
