<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Drafting\DraftGuard;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageDraftingEngine;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Drafting\Sentinel;
use App\ContentEngine\Drafting\SlotShaper;
use App\Enums\ContentStatus;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\PageBuilder\Validation\KitValidator;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

function pageEngine(FakeClaudeClient $claude): PageDraftingEngine
{
    return new PageDraftingEngine(
        new PageGroundingAssembler,
        new PageDrafter(new DraftCall($claude)),
        new DraftGuard,
        app(KitValidator::class),
        new SlotShaper,
    );
}

function proofIdFor(string $siteId): string
{
    return (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->value('id');
}

it('drafts a kit-keyed slot map in place → needs_review with image specs', function () {
    $page = PageFixture::intakePage();
    $claude = new FakeClaudeClient(PageFixture::validResponse(proofIdFor($page->site_id)));

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and($drafted->body)->toBeNull()
        ->and($drafted->hasDraft())->toBeTrue()
        ->and($drafted->slot_payload['svc_intro'])->toContain('water heater')
        ->and($drafted->slot_payload['faq'])->toHaveCount(3)
        ->and($drafted->meta['image_specs'])->not->toBeEmpty()
        ->and($drafted->wireframe_kit_version)->not->toBeNull();

    // The shared proof-prose rule (DraftCall core) reaches the PAGE prompt too —
    // page 196 spliced faker offer terms verbatim into FAQ copy.
    expect($claude->prompts[0])->toContain('NEVER splice an entity')
        ->and($claude->prompts[0])->toContain('NEVER emit a placeholder, citation, or annotation token');
});

it('drops off-schema slot keys (the slot key is the render contract)', function () {
    $page = PageFixture::intakePage();
    $claude = new FakeClaudeClient(PageFixture::validResponse(proofIdFor($page->site_id), [
        'totally_made_up_slot' => 'this should never persist',
    ]));

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->slot_payload)->not->toHaveKey('totally_made_up_slot')
        ->and($drafted->slot_payload)->toHaveKey('svc_intro');
});

it('surfaces a missing required slot as a draft failure — no status flip', function () {
    $page = PageFixture::intakePage(['status' => ContentStatus::Scored]);
    // Omit the required svc_intro slot — drop its sentinel block.
    $response = PageFixture::validResponse(proofIdFor($page->site_id));
    $response = str_replace(
        Sentinel::block('svc_intro', 'An aging water heater rarely fails politely. It declines for months — lukewarm showers, a creeping utility bill, '
            .'rusty water, then a sudden cold morning. We right-size a modern tankless system to your household demand and install it cleanly in a single visit.'),
        '',
        $response,
    );
    $claude = new FakeClaudeClient($response);

    try {
        pageEngine($claude)->draftPage($page->fresh());
        $this->fail('expected DraftFailedException');
    } catch (DraftFailedException $e) {
        expect($e->getMessage())->toContain('kit schema');
    }

    $after = $page->fresh();
    expect($after->status)->toBe(ContentStatus::Scored) // not flipped to needs_review
        ->and($after->draftError())->toContain('kit schema');
});

it('surfaces budget exhaustion (empty page draft) through the shared guard', function () {
    $page = PageFixture::intakePage(['status' => ContentStatus::Scored]);
    $claude = new FakeClaudeClient('', stopReason: 'max_tokens', outputTokens: 12000, thinkingTokens: 8000);

    expect(fn () => pageEngine($claude)->draftPage($page->fresh()))
        ->toThrow(DraftFailedException::class);

    $after = $page->fresh();
    expect($after->status)->toBe(ContentStatus::Scored)
        ->and($after->meta['draft_failure']['stop_reason'])->toBe('max_tokens');
});
