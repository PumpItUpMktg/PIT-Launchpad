<?php

use App\Integrations\Claude\ClaudeClient;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;
use Tests\Support\SequencedClaudeClient;

test('the command converses, extracts, and persists on confirm', function () {
    $site = Site::factory()->create();

    // One sequence serves both the interviewer (turns) and the extractor (final call).
    $this->app->instance(ClaudeClient::class, new SequencedClaudeClient([
        (string) json_encode(['message' => 'What do you do?', 'ready' => false]),
        (string) json_encode(['message' => 'Thanks, I have enough.', 'ready' => true]),
        (string) json_encode([
            'seed' => [
                'trade' => 'waterproofing',
                'anchor_services' => ['Sump Pump Installation'],
                'markets' => ['Tucson'],
                'exclusions' => [],
            ],
            'voice' => ['framing_model' => 'problem_solution', 'tone_axes' => ['warmth' => 0.8]],
        ]),
    ]));

    $this->artisan('launchpad:interview', ['site' => $site->id])
        ->expectsQuestion('You', 'We waterproof basements around Tucson.')
        ->expectsConfirmation('Persist this seed + voice profile to '.($site->brand_name ?? $site->id).'?', 'yes')
        ->assertSuccessful();

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('trade'))
        ->toBe('waterproofing');
    expect(VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('status', 'active')->count())->toBe(1);
});

test('the command errors on an unknown site', function () {
    $this->artisan('launchpad:interview', ['site' => 'missing'])->assertFailed();
});
