<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Drafting\GroundingAssembler;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Models\Site;

function groundingSources(?string $sourceUrl, ?string $sourceBody)
{
    $site = Site::factory()->create();
    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'Tankless rebate announced',
        angleHint: 'How homeowners save',
        sourceName: 'Austin Tribune',
        sourceUrl: $sourceUrl,
        sourceBody: $sourceBody,
    );

    return (new GroundingAssembler)->assemble($request)->sources;
}

it('grounds a Google News item (no source_url) on metadata, cited by name', function () {
    $sources = groundingSources(sourceUrl: null, sourceBody: null);

    expect($sources)->toHaveCount(1);
    expect($sources[0]->name)->toBe('Austin Tribune')
        ->and($sources[0]->url)->toBeNull()
        ->and($sources[0]->summary)->toBe('Tankless rebate announced — How homeowners save');
});

it('grounds a direct-feed item that has a body on the body, with its link', function () {
    $sources = groundingSources(sourceUrl: 'https://tribune.example/story', sourceBody: 'Full article body the model can ground on.');

    expect($sources[0]->url)->toBe('https://tribune.example/story')
        ->and($sources[0]->summary)->toBe('Full article body the model can ground on.');
});

it('falls back to metadata when a source_url item has no captured body yet', function () {
    $sources = groundingSources(sourceUrl: 'https://tribune.example/story', sourceBody: null);

    expect($sources[0]->url)->toBe('https://tribune.example/story')
        ->and($sources[0]->summary)->toBe('Tankless rebate announced — How homeowners save');
});
