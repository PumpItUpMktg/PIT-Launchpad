<?php

use App\Enums\VoiceStatus;
use App\Interview\ExtractionResult;
use App\Interview\InterviewPersister;
use App\Interview\SiloSeed;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;

function extraction(string $trade = 'waterproofing'): ExtractionResult
{
    return new ExtractionResult(
        new SiloSeed($trade, ['Sump Pump Installation', 'Basement Waterproofing'], 'NJ, eastern PA', ['Roofing']),
        [
            'framing_model' => 'problem_solution',
            'tone_axes' => ['formality' => 0.3, 'warmth' => 0.8],
            'reading_level' => 'grade_8',
            'persona' => ['perspective' => 'we', 'identity' => 'basement experts'],
            'language_rules' => ['preferred' => ['waterproofing'], 'banned' => []],
            'audience' => ['primary' => 'homeowners'],
            'cta_voice' => 'direct',
        ],
    );
}

test('it persists the seed + transcript onto a blueprint and the voice as an active profile', function () {
    $site = Site::factory()->create();

    $transcript = [
        ['role' => 'assistant', 'text' => 'Tell me about your business.'],
        ['role' => 'owner', 'text' => 'We waterproof basements around Tucson.'],
    ];

    $result = app(InterviewPersister::class)->persist($site, extraction(), $transcript);

    expect($result->blueprint->trade)->toBe('waterproofing')
        ->and($result->blueprint->seed['anchor_services'])->toContain('Sump Pump Installation')
        ->and($result->blueprint->transcript)->toBe($transcript)
        ->and($result->blueprint->site_id)->toBe($site->id)
        ->and($result->voice->status)->toBe(VoiceStatus::Active)
        ->and($result->voice->version)->toBe(1)
        ->and($result->voice->reading_level)->toBe('grade_8')
        ->and($result->voice->cta_voice)->toBe('direct');

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

test('genuinely-unprovided fields persist EMPTY, never fabricated', function () {
    $site = Site::factory()->create();

    // The owner gave a trade + one service but no region and no exclusions.
    $sparse = new ExtractionResult(
        new SiloSeed('handyman', ['General Repairs'], '', []),
        ['framing_model' => 'problem_solution', 'tone_axes' => []],
    );

    $result = app(InterviewPersister::class)->persist($site, $sparse);

    expect($result->blueprint->seed['region'])->toBe('')
        ->and($result->blueprint->seed['exclusions'])->toBe([])
        ->and($result->blueprint->trade)->toBe('handyman');
});

test('re-running updates the one blueprint and supersedes the active voice version', function () {
    $site = Site::factory()->create();
    $persister = app(InterviewPersister::class);

    $first = $persister->persist($site, extraction('plumbing'));
    $second = $persister->persist($site, extraction('waterproofing'));

    // One blueprint per site, updated in place.
    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and($second->blueprint->trade)->toBe('waterproofing');

    // New active version; the prior one archived (one active per site holds).
    expect($second->voice->version)->toBe(2)
        ->and($second->voice->status)->toBe(VoiceStatus::Active);

    expect(VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('status', VoiceStatus::Active)->count())->toBe(1);

    $first->voice->refresh();
    expect($first->voice->status)->toBe(VoiceStatus::Archived);
});
