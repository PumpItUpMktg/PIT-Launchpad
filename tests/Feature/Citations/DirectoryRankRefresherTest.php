<?php

use App\Citations\DirectoryRankRefresher;
use App\Integrations\DomainAuthority\DomainAuthorityProvider;
use App\Integrations\DomainAuthority\MockDomainAuthorityProvider;
use App\Models\Directory;

test('the default mock provider never fabricates a rank', function (): void {
    $refresher = new DirectoryRankRefresher(new MockDomainAuthorityProvider);
    $dir = Directory::factory()->create(['domain_rank' => null]);

    expect($refresher->refresh($dir))->toBeFalse()
        ->and($dir->refresh()->domain_rank)->toBeNull();
});

test('a real provider writes the domain rank', function (): void {
    $provider = new class implements DomainAuthorityProvider
    {
        public function rankFor(string $domain): ?int
        {
            return $domain === 'yelp.com' ? 93 : null;
        }
    };
    $refresher = new DirectoryRankRefresher($provider);
    $dir = Directory::factory()->create(['domain' => 'yelp.com', 'domain_rank' => null]);

    expect($refresher->refresh($dir))->toBeTrue()
        ->and($dir->refresh()->domain_rank)->toBe(93);
});

test('the rate-directories command recomputes seo value for active directories', function (): void {
    $dir = Directory::factory()->create(['domain_rank' => 66, 'seo_value' => null, 'is_active' => true]);

    $this->artisan('launchpad:rate-directories', ['--no-refresh' => true])->assertSuccessful();

    expect($dir->refresh()->seo_value)->toBe(66);
});
