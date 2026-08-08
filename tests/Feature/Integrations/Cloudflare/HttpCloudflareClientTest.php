<?php

use App\Integrations\Cloudflare\HttpCloudflareClient;
use Illuminate\Support\Facades\Http;

const RULE_DESC = 'Launchpad control-plane sync (auto-managed) — allow /wp-json/launchpad/*';

it('verifies an active token and rejects an inactive/absent one', function () {
    Http::fakeSequence('*/user/tokens/verify')
        ->push(['result' => ['status' => 'active']], 200)
        ->push(['result' => ['status' => 'disabled']], 200);

    expect((new HttpCloudflareClient('tok'))->verifyToken())->toBeTrue()
        ->and((new HttpCloudflareClient('tok'))->verifyToken())->toBeFalse();

    // No token → never calls the API.
    expect((new HttpCloudflareClient(''))->verifyToken())->toBeFalse();
});

it('resolves the zone by apex fallback (www.acme.com → acme.com)', function () {
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/zones') && str_contains($url, 'name=acme.com')) {
            return Http::response(['result' => [['id' => 'ZONE123']]], 200);
        }

        return Http::response(['result' => []], 200); // www.acme.com has no zone of its own
    });

    expect((new HttpCloudflareClient('tok'))->zoneIdForDomain('www.acme.com'))->toBe('ZONE123');
});

it('returns null when no zone matches the domain', function () {
    Http::fake(['*/zones*' => Http::response(['result' => []], 200)]);

    expect((new HttpCloudflareClient('tok'))->zoneIdForDomain('not-on-cloudflare.com'))->toBeNull();
});

it('creates the skip rule when none exists, prepended and scoped to the launchpad path', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/entrypoint')) {
            return Http::response(['result' => ['rules' => [
                ['id' => 'OTHER', 'description' => 'someone else rule', 'expression' => 'true', 'action' => 'block'],
            ]]], 200);
        }

        // PUT echoes back rules with ids assigned.
        return Http::response(['result' => ['rules' => [
            ['id' => 'LP1', 'description' => RULE_DESC],
            ['id' => 'OTHER', 'description' => 'someone else rule'],
        ]]], 200);
    });

    $result = (new HttpCloudflareClient('tok'))->ensureLaunchpadSkipRule('ZONE123');

    expect($result->ok)->toBeTrue()
        ->and($result->action)->toBe('created')
        ->and($result->ruleId)->toBe('LP1');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        $rules = $request->data()['rules'] ?? [];

        // Our rule is FIRST, is a skip, targets only the launchpad path, and the pre-existing rule survives.
        return ($rules[0]['description'] ?? null) === RULE_DESC
            && $rules[0]['action'] === 'skip'
            && str_contains($rules[0]['expression'], '/wp-json/launchpad/')
            && collect($rules)->firstWhere('description', 'someone else rule') !== null;
    });
});

it('is idempotent — an existing launchpad rule is replaced, not duplicated', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/entrypoint')) {
            return Http::response(['result' => ['rules' => [
                ['id' => 'LP-OLD', 'description' => RULE_DESC, 'action' => 'skip', 'expression' => 'stale'],
            ]]], 200);
        }

        return Http::response(['result' => ['rules' => [['id' => 'LP-NEW', 'description' => RULE_DESC]]]], 200);
    });

    $result = (new HttpCloudflareClient('tok'))->ensureLaunchpadSkipRule('ZONE123');

    expect($result->action)->toBe('updated')
        ->and($result->ruleId)->toBe('LP-NEW');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        $rules = $request->data()['rules'] ?? [];

        // Exactly one launchpad rule survives (no stacking).
        return collect($rules)->where('description', RULE_DESC)->count() === 1;
    });
});

it('surfaces a Cloudflare API error message on write failure', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response(['result' => ['rules' => []]], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'Insufficient permissions']]], 403);
    });

    $result = (new HttpCloudflareClient('tok'))->ensureLaunchpadSkipRule('ZONE123');

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Insufficient permissions');
});
