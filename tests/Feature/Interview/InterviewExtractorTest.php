<?php

use App\Interview\InterviewExtractor;
use App\Interview\SeedExtractionException;
use App\Interview\SeedValidator;
use Tests\Support\FakeClaudeClient;

/** Build a well-formed extractor payload for a trade. */
function seedPayload(string $trade, array $anchors, array $markets = [], array $exclusions = []): string
{
    return (string) json_encode([
        'seed' => [
            'trade' => $trade,
            'anchor_services' => $anchors,
            'markets' => $markets,
            'exclusions' => $exclusions,
        ],
        'voice' => [
            'framing_model' => 'problem_solution',
            'tone_axes' => ['formality' => 0.4, 'warmth' => 0.7],
            'reading_level' => 'grade_8',
            'persona' => ['perspective' => 'we', 'identity' => 'local expert', 'credibility' => 'licensed'],
            'language_rules' => ['preferred' => [], 'banned' => []],
            'audience' => ['primary' => 'homeowners'],
            'cta_voice' => 'direct',
        ],
    ]);
}

function extractorReturning(string $response): InterviewExtractor
{
    return new InterviewExtractor(new FakeClaudeClient($response), new SeedValidator);
}

test('it extracts a well-formed seed + voice for a waterproofing business (no GBP)', function () {
    $json = seedPayload(
        'waterproofing',
        ['Sump Pump Installation', 'Basement Waterproofing'],
        ['Tucson', 'Oro Valley'],
        ['Roofing'],
    );

    $result = extractorReturning($json)->extract(
        'We keep basements dry — sump pumps and full waterproofing systems around Tucson. We do not do roofing.',
    );

    expect($result->seed->trade)->toBe('waterproofing')
        ->and($result->seed->anchorServices)->toContain('Sump Pump Installation')
        ->and($result->seed->markets)->toContain('Tucson')
        ->and($result->seed->exclusions)->toContain('Roofing')
        ->and($result->seed->gbpSignals)->toBeNull()
        ->and($result->voice['framing_model'])->toBe('problem_solution')
        ->and($result->voice['tone_axes'])->toBeArray();
});

test('it grounds a roofer against connected GBP signals and carries them onto the seed', function () {
    $json = seedPayload('roofing', ['Roof Replacement', 'Roof Repair'], ['Denver']);
    $gbp = ['Roofing Contractor', 'Roof Repair', 'Gutter Service'];

    $result = extractorReturning($json)->extract('We replace and repair roofs across the Denver metro.', $gbp);

    expect($result->seed->trade)->toBe('roofing')
        ->and($result->seed->gbpSignals)->toBe($gbp);
});

test('it extracts an HVAC business', function () {
    $json = seedPayload('hvac', ['AC Repair', 'Furnace Installation'], ['Phoenix']);

    $result = extractorReturning($json)->extract('Heating and cooling — AC and furnace work in Phoenix.');

    expect($result->seed->trade)->toBe('hvac')
        ->and($result->seed->anchorServices)->toHaveCount(2);
});

test('it rejects a malformed model output (no anchors) rather than emit a bad seed', function () {
    $bad = (string) json_encode([
        'seed' => ['trade' => 'plumbing', 'anchor_services' => [], 'markets' => [], 'exclusions' => []],
        'voice' => ['framing_model' => 'problem_solution', 'tone_axes' => []],
    ]);

    extractorReturning($bad)->extract('A plumbing business.');
})->throws(SeedExtractionException::class);

test('it rejects a non-JSON model response', function () {
    extractorReturning('I cannot help with that.')->extract('A plumbing business.');
})->throws(SeedExtractionException::class);

test('the validator flags each missing piece', function () {
    $errors = (new SeedValidator)->validate(['seed' => ['trade' => ''], 'voice' => 'nope']);

    expect($errors)->toContain('seed.trade is empty.')
        ->and($errors)->toContain('seed.anchor_services must list at least one service.')
        ->and($errors)->toContain('Missing "voice" object.');
});
