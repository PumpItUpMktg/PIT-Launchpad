<?php

use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Expansion\ExpansionException;
use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Interview\SiloSeed;
use Tests\Support\ExpansionFixture;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SequencedClaudeClient;

function expander(string $response): SiloExpander
{
    return new SiloExpander(new FakeClaudeClient($response), new ExpansionValidator);
}

function seed(): SiloSeed
{
    return new SiloSeed('waterproofing', ['Sump Pump Installation', 'Basement Waterproofing'], 'NJ, eastern PA', ['Roofing']);
}

test('it expands a confirmed seed into a validated candidate tree', function () {
    $result = expander(ExpansionFixture::json())->expand(seed());

    expect($result->silos)->toHaveCount(4)
        ->and($result->spokeCount())->toBe(10)
        ->and($result->fringeHandoff)->toHaveCount(2);

    $sump = $result->silos[0];
    expect($sump->name)->toBe('Sump Pumps')
        ->and($sump->headKeyword)->toBe('sump pump')
        ->and($sump->spokes[0]->tag)->toBe(SpokeTag::Core)
        ->and($sump->spokes[0]->pageType)->toBe(SpokePageType::Service)
        ->and($sump->spokes[0]->headKeyword)->toBe('sump pump installation');

    // the upstream content page is typed content
    $content = collect($sump->spokes)->firstWhere('name', 'Why Is My Basement Wet?');
    expect($content->pageType)->toBe(SpokePageType::Content);
});

test('connecting spokes carry their problem-chain connection note', function () {
    $result = expander(ExpansionFixture::json())->expand(seed());

    $drainage = collect($result->silos)->firstWhere('name', 'Waterproofing & Drainage');
    $gutters = collect($drainage->spokes)->firstWhere('name', 'Gutter Installation');

    expect($gutters->tag)->toBe(SpokeTag::Connecting)
        ->and($gutters->connectionNote)->toBe('gutters — a cause of basement water');
});

test('the fringe handoff set carries connection notes + sibling-brand hints (no service pages)', function () {
    $result = expander(ExpansionFixture::json())->expand(seed());

    $mold = collect($result->fringeHandoff)->firstWhere('name', 'Mold Remediation');
    expect($mold->siblingBrand)->toBe('Trusted Mold')
        ->and($mold->connectionNote)->toBe('mold from chronic basement moisture');

    // fringe items are NOT silos/spokes
    expect(collect($result->silos)->pluck('name'))->not->toContain('Mold Remediation');
});

test('audience and brand manifest as their own silos, not new fields', function () {
    $result = expander(ExpansionFixture::json())->expand(seed());
    $names = collect($result->silos)->pluck('name');

    expect($names)->toContain('Commercial & Industrial')   // audience axis
        ->and($names)->toContain('Brands We Service');     // brand axis
});

test('it normalizes enum drift (own-page, capitalized tags)', function () {
    $tree = ExpansionFixture::tree();
    $tree['silos'][0]['spokes'][0]['granularity'] = 'own-page';
    $tree['silos'][0]['spokes'][0]['tag'] = 'Core';

    $result = expander((string) json_encode($tree))->expand(seed());

    expect($result->silos[0]->spokes[0]->tag)->toBe(SpokeTag::Core)
        ->and($result->silos[0]->spokes[0]->granularity->value)->toBe('own_page');
});

test('it retries then throws on an unrecoverable tree', function () {
    expander('not json at all')->expand(seed());
})->throws(ExpansionException::class);

test('it rejects a connecting spoke with no connection note', function () {
    $tree = ExpansionFixture::tree();
    $tree['silos'][1]['spokes'][0]['connection_note'] = ''; // a connecting spoke

    expander((string) json_encode($tree))->expand(seed());
})->throws(ExpansionException::class);

test('the validator flags structural problems', function () {
    $errors = (new ExpansionValidator)->validate([
        'silos' => [
            ['name' => '', 'spokes' => []],
            ['name' => 'Pumps', 'spokes' => [['name' => 'x', 'head_keyword' => '', 'tag' => 'bogus', 'page_type' => 'service']]],
        ],
    ]);

    expect($errors)->toContain('silos[0].name is empty.')
        ->and(collect($errors)->contains(fn ($e) => str_contains($e, 'has no spokes')))->toBeTrue()
        ->and(collect($errors)->contains(fn ($e) => str_contains($e, 'head_keyword is empty')))->toBeTrue()
        ->and(collect($errors)->contains(fn ($e) => str_contains($e, 'tag is not one of')))->toBeTrue();
});

test('status candidate is the pre-prune marker', function () {
    expect(SpokeStatus::Candidate->value)->toBe('candidate');
});

test('it tolerates markdown-fenced JSON from the live model', function () {
    $fenced = "Here you go:\n```json\n".ExpansionFixture::json()."\n```";

    $result = expander($fenced)->expand(seed());

    expect($result->silos)->toHaveCount(4);
});

test('it reconciles a legitimate top-level array into the silos shape', function () {
    // The model returns just the silos array (no wrapping object).
    $silosOnly = (string) json_encode(ExpansionFixture::tree()['silos']);

    $result = expander($silosOnly)->expand(seed());

    expect($result->silos)->toHaveCount(4)
        ->and($result->fringeHandoff)->toBe([]); // no fringe in a bare array
});

test('decomposed expansion plans silos then fills each with its own call', function () {
    $plan = (string) json_encode([
        'silos' => [
            ['name' => 'Sump Pumps', 'head_keyword' => 'sump pump', 'page_type' => 'service', 'focus' => 'sump pump x action'],
            ['name' => 'Brands We Service', 'head_keyword' => 'pump brands', 'page_type' => 'service'],
        ],
        'fringe_handoff' => [['name' => 'Mold Remediation', 'connection_note' => 'chronic moisture', 'sibling_brand' => 'Trusted Mold']],
    ]);
    $sumpSpokes = (string) json_encode(['spokes' => [
        ['name' => 'Sump Pump Installation', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'sump pump installation', 'granularity' => 'own_page'],
        ['name' => 'Battery Backup', 'page_type' => 'service', 'tag' => 'adjacent', 'head_keyword' => 'sump pump battery backup', 'granularity' => 'own_page'],
    ]]);
    $brandSpokes = (string) json_encode(['spokes' => [
        ['name' => 'Zoeller', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'zoeller pumps', 'granularity' => 'own_page'],
    ]]);

    $expander = new SiloExpander(new SequencedClaudeClient([$plan, $sumpSpokes, $brandSpokes]), new ExpansionValidator);
    $result = $expander->expandDecomposed(seed());

    expect($result->silos)->toHaveCount(2)
        ->and($result->silos[0]->name)->toBe('Sump Pumps')
        ->and($result->silos[0]->spokes)->toHaveCount(2)
        ->and($result->silos[1]->spokes)->toHaveCount(1)
        ->and($result->fringeHandoff)->toHaveCount(1)
        ->and($result->fringeHandoff[0]->siblingBrand)->toBe('Trusted Mold');
});
