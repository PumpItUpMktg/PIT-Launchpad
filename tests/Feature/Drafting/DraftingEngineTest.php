<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

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
