<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Models\Market;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

test('a locally-relevant reactive draft may reference the site towns', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Springfield']);

    $claude = new FakeClaudeClient(Draft::post($claim->id, ['towns' => ['Springfield']]));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'Cold snap warning',
        sourceName: 'Local Tribune',
        localRelevance: true,
    );

    $content = DraftingHarness::engine($claude)->run($request)->content;

    expect($claude->prompts[0])->toContain('Springfield')
        ->and($claude->prompts[0])->toContain('MAY naturally reference')
        ->and($content->local_relevance)->toBeTrue()
        ->and($content->meta['towns'])->toContain('Springfield');
});

test('a directed (evergreen) draft is kept town-agnostic even when markets exist', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Springfield']);

    $claude = new FakeClaudeClient(Draft::post($claim->id));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Directed,
        trigger: DraftTrigger::Gap,
        title: 'How tankless heaters work',
        localRelevance: false,
    );

    DraftingHarness::engine($claude)->run($request);

    expect($claude->prompts[0])->toContain('town-agnostic')
        ->and($claude->prompts[0])->not->toContain('Springfield');
});

test('a reactive draft without local relevance is not localized', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Springfield']);

    $claude = new FakeClaudeClient(Draft::post($claim->id));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'National rebate news',
        sourceName: 'Wire',
        localRelevance: false,
    );

    DraftingHarness::engine($claude)->run($request);

    expect($claude->prompts[0])->toContain('town-agnostic');
});
