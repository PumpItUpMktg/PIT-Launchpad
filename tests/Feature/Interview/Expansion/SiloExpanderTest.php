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
