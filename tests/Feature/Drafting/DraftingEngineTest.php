<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

test('the post drafting prompt forbids a body <h1> (the title is rendered separately)', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id));

    DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site));

    expect($claude->prompts[0])->toContain('do NOT include the article title or any <h1>')
        ->and($claude->prompts[0])->toContain('<h2>');
});

test('a drafted SEO title carries no pipe-suffix and no source-name attribution, capped for SERP', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    // postRequest's source is "Local Tribune" — the drafter emits a dirty title.
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'seo' => [
            'title' => 'Tankless Water Heater Rebates Explained | Local Tribune',
            'meta_description' => 'x',
            'slug' => 'whatever',
        ],
    ]));

    $content = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site))->content;

    // The SEO title (the document <title>) is the drafter's seo.title, normalized.
    expect($content->meta['seo']['title'])->toBe('Tankless Water Heater Rebates Explained')
        ->and($content->meta['seo']['title'])->not->toContain('|')
        ->and($content->meta['seo']['title'])->not->toContain('Local Tribune')
        ->and(mb_strlen($content->meta['seo']['title']))->toBeLessThanOrEqual(60);

    expect($claude->prompts[0])->toContain('NO publication/source names')
        ->and($claude->prompts[0])->toContain('≤60 chars');
});

test('the post drafting prompt forbids placeholder/citation tokens and verbatim proof splicing', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id));

    DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site));

    expect($claude->prompts[0])->toContain('Do NOT emit ANY placeholder, citation, or annotation token')
        ->and($claude->prompts[0])->toContain('never text to splice in')
        ->and($claude->prompts[0])->toContain('omit that sentence entirely');
});

test('a post draft is emitted as needs_review with a body, pinned voice, SEO and verification', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id));

    $result = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site));
    $content = $result->content;

    expect($content->status)->toBe(ContentStatus::NeedsReview)
        ->and($content->kind)->toBe(ContentKind::Post)
        ->and($content->body)->not->toBeNull()
        ->and($content->slot_payload)->toBeNull()
        ->and($content->voice_profile_version)->toBe(3)
        ->and($content->draft_trigger)->toBe(DraftTrigger::News)
        ->and($content->draft_lane)->toBe('reactive')
        ->and($content->source_name)->toBe('Local Tribune')
        ->and($content->meta['seo']['title'])->toBe('Tankless Water Heater Rebates Explained')
        ->and($content->verification['passed'])->toBeTrue()
        ->and($content->verification['supported_claims'])->toHaveCount(1);
});

test('an unsupported claim is flagged but the draft still ships to review', function () {
    ['site' => $site] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post('not-a-real-claim-id'));

    $result = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site));

    expect($result->verification->passed())->toBeFalse()
        ->and($result->verification->unsupportedClaims)->toHaveCount(1)
        ->and($result->content->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->content->body)->not->toBeNull()
        ->and($result->content->verification['unsupported_claims'][0]['text'])
        ->toContain('warranty');
});

test('a claim with a null id is treated as unsupported', function () {
    ['site' => $site] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::json([
        'body' => '<p>Body.</p>',
        'claims_used' => [['text' => 'We are the best in town.', 'claim_id' => null]],
    ]));

    $result = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site));

    expect($result->verification->unsupportedClaims)->toHaveCount(1)
        ->and($result->verification->supportedClaims)->toBeEmpty();
});

test('SEO and image specs are emitted without rendering anything', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'images' => [[
            'slot' => 'hero_image',
            'prompt' => 'A modern tankless heater on a clean utility wall',
            'seo_filename' => 'tankless-water-heater.jpg',
            'alt' => 'Wall-mounted tankless water heater',
            'title' => 'Tankless heater',
            'caption' => 'A space-saving install.',
        ]],
    ]));

    $content = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site))->content;

    expect($content->meta['image_specs'])->toHaveCount(1)
        ->and($content->meta['image_specs'][0]['slot'])->toBe('hero_image')
        ->and($content->meta['image_specs'][0]['seo_filename'])->toBe('tankless-water-heater.jpg')
        ->and($content->meta['seo']['og']['title'])->toBe('Tankless Rebates')
        // No media asset is created — §6b emits specs only.
        ->and($content->media()->count())->toBe(0);
});
