<?php

use App\JobCapture\Enhancement\DescriptionEnhancer;
use Tests\Support\FakeClaudeClient;

it('polishes rough notes into a write-up via the Claude seam', function () {
    $fake = new FakeClaudeClient('Replaced a failed sump pump and verified the new unit under load.');

    $out = (new DescriptionEnhancer($fake))->enhance('swapped pump', ['Sump Pump'], 'Somerville, Somerset County');

    expect($out)->toBe('Replaced a failed sump pump and verified the new unit under load.')
        ->and($fake->prompts[0])->toContain('swapped pump')          // the notes are the source of truth
        ->and($fake->prompts[0])->toContain('Sump Pump')             // service context varied in
        ->and($fake->prompts[0])->toContain('Somerville, Somerset County');
});

it('returns empty for empty notes without calling the model', function () {
    $fake = new FakeClaudeClient('should not be used');

    $out = (new DescriptionEnhancer($fake))->enhance('   ');

    expect($out)->toBe('')
        ->and($fake->prompts)->toBe([]);
});
