<?php

use App\Enums\ConnectionProvider;
use App\Integrations\Cloudflare\CloudflareClient;
use App\Integrations\Cloudflare\CloudflareRuleResult;
use App\Integrations\Cloudflare\MockCloudflareClient;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\CloudflareConfigurator;

/** A CloudflareClient with per-call behavior for testing the orchestration branches. */
function fakeCf(bool $tokenOk = true, ?string $zoneId = 'ZONE1', ?CloudflareRuleResult $rule = null): CloudflareClient
{
    return new class($tokenOk, $zoneId, $rule) implements CloudflareClient
    {
        public function __construct(private bool $tokenOk, private ?string $zoneId, private ?CloudflareRuleResult $rule) {}

        public function verifyToken(): bool
        {
            return $this->tokenOk;
        }

        public function zoneIdForDomain(string $domain): ?string
        {
            return $this->zoneId;
        }

        public function ensureLaunchpadSkipRule(string $zoneId): CloudflareRuleResult
        {
            return $this->rule ?? CloudflareRuleResult::created('RULE1');
        }
    };
}

it('reports not-configured when no Cloudflare token is set', function () {
    config()->set('services.cloudflare.api_token', '');
    app()->instance(CloudflareClient::class, new MockCloudflareClient);

    $result = app(CloudflareConfigurator::class)->configureForUrl('https://acme.com');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('not_configured');
});

it('configures the edge for a resolvable zone', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, new MockCloudflareClient);

    $result = app(CloudflareConfigurator::class)->configureForUrl('https://www.acme.com/');

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('configured')
        ->and($result->zoneId)->not->toBeNull()
        ->and($result->message)->toContain('acme.com')
        ->and($result->message)->toContain('/wp-json/launchpad/*');
});

it('reports invalid_token when the token is rejected', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, fakeCf(tokenOk: false));

    expect(app(CloudflareConfigurator::class)->configureForUrl('https://acme.com')->status)->toBe('invalid_token');
});

it('reports no_zone when the domain is not on the account', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, fakeCf(zoneId: null));

    $result = app(CloudflareConfigurator::class)->configureForUrl('https://not-on-cf.com');

    expect($result->status)->toBe('no_zone')
        ->and($result->message)->toContain('not-on-cf.com');
});

it('surfaces a rule-write failure', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, fakeCf(rule: CloudflareRuleResult::failed('Insufficient permissions')));

    $result = app(CloudflareConfigurator::class)->configureForUrl('https://acme.com');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('failed')
        ->and($result->message)->toContain('Insufficient permissions');
});

it('the command resolves a site\'s saved WordPress URL and configures it', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, new MockCloudflareClient);

    $site = Site::factory()->create();
    Connection::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://acme.com', 'username' => 'launchpad-sync', 'app_password' => 'x'],
        'status' => 'active', 'compromised' => false, 'last_rotated_at' => now(),
    ]);

    $this->artisan('launchpad:configure-cloudflare', ['site' => $site->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('/wp-json/launchpad/*');
});

it('the command accepts a bare domain', function () {
    config()->set('services.cloudflare.api_token', 'tok');
    app()->instance(CloudflareClient::class, new MockCloudflareClient);

    $this->artisan('launchpad:configure-cloudflare', ['site' => 'acme.com'])
        ->assertExitCode(0);
});
