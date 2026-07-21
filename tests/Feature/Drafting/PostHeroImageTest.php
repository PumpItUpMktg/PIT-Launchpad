<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

test('the post drafter asks for a required hero image spec (so posts do not publish imageless)', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'Storm season prep',
        sourceName: 'Wire',
    );

    DraftingHarness::engine($claude)->run($request);

    expect($claude->prompts[0])->toContain('image.hero_image')
        ->and($claude->prompts[0])->toContain('HERO IMAGE');
});

test('a drafted post carries its hero image spec into meta.image_specs (rendered at publish)', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();

    // The drafter returns a hero image SPEC alongside the body.
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'images' => [[
            'slot' => 'hero_image',
            'prompt' => 'A homeowner checking a sump pump in a clean basement',
            'seo_filename' => 'sump-pump-check.webp',
            'alt' => 'Homeowner checking a sump pump',
        ]],
    ]));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Post,
        intakeType: IntakeType::Reactive,
        trigger: DraftTrigger::News,
        title: 'Check your sump pump',
        sourceName: 'Wire',
    );

    $content = DraftingHarness::engine($claude)->run($request)->content;

    $specs = $content->meta['image_specs'];
    expect($specs)->toHaveCount(1)
        ->and($specs[0]['slot'])->toBe('hero_image')
        ->and($specs[0]['seo_filename'])->toBe('sump-pump-check.webp');
});
