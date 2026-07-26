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
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\PageBuilder\Validation\KitValidator;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

function pageEngine(ClaudeClient $claude): PageDraftingEngine
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

it('clamps over-length text to the kit max instead of failing the page (the 221>220 case)', function () {
    $page = PageFixture::intakePage();
    // The drafter overshoots: a subhead past the 220 cap and an intro past the 900 cap. Pre-clamp,
    // either LengthAboveMaximum hard-rejected the whole page ("Your move — try again").
    $claude = new FakeClaudeClient(PageFixture::validResponse(proofIdFor($page->site_id), [
        'hero_subhead' => str_repeat('an extra-long benefit line ', 12),   // ~324 chars > 220
        'svc_intro' => str_repeat('This service explains the problem and the honest fix in plain terms. ', 20), // ~1360 > 900
    ]));

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)   // drafted, not failed
        ->and($drafted->hasDraft())->toBeTrue()
        ->and($drafted->draftError())->toBeNull()
        ->and(mb_strlen(trim($drafted->slot_payload['hero_subhead'])))->toBeLessThanOrEqual(220)
        ->and(mb_strlen(trim($drafted->slot_payload['svc_intro'])))->toBeLessThanOrEqual(900)
        ->and(mb_strlen(trim($drafted->slot_payload['svc_intro'])))->toBeGreaterThanOrEqual(120); // still ≥ min
});

it('flags section headings that fell back to the static label — drafted ones are not flagged', function () {
    $page = PageFixture::intakePage();
    // The drafter fills two section H2s and leaves the rest empty (they render the static label).
    $claude = new FakeClaudeClient(PageFixture::validResponse(proofIdFor($page->site_id), [
        'symptoms_heading' => 'Warning signs your tank is failing',
        'scope_heading' => 'Everything a tankless install includes',
    ]));

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->slot_payload['symptoms_heading'])->toBe('Warning signs your tank is failing')
        // The empty section headings are flagged in the generation log (meta) …
        ->and($drafted->meta['heading_fallbacks'])->toContain('overview_heading')
        ->and($drafted->meta['heading_fallbacks'])->toContain('process_heading')
        ->and($drafted->meta['heading_fallbacks'])->toContain('faq_heading')
        // … the drafted ones are NOT.
        ->and($drafted->meta['heading_fallbacks'])->not->toContain('symptoms_heading')
        ->and($drafted->meta['heading_fallbacks'])->not->toContain('scope_heading');
});

it('stamps no heading_fallbacks when every section heading drafted', function () {
    $page = PageFixture::intakePage();
    $claude = new FakeClaudeClient(PageFixture::validResponse(proofIdFor($page->site_id), [
        'overview_heading' => 'What tankless installation covers',
        'symptoms_heading' => 'Signs your old tank is done',
        'scope_heading' => 'What a full install includes',
        'process_heading' => 'How the install day runs',
        'cost_heading' => 'What a tankless install costs',
        'related_heading' => 'Other work you may need',
        'faq_heading' => 'Tankless questions, answered',
    ]));

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    // Absent key ⇒ every H2 was drafted; the static label was never the default path.
    expect($drafted->meta)->not->toHaveKey('heading_fallbacks')
        ->and($drafted->slot_payload['process_heading'])->toBe('How the install day runs');
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

/**
 * A ClaudeClient double that returns a QUEUE of responses (the last repeats) — so a test can drive the
 * first draft and its bounded retry with different payloads. Records prompts like FakeClaudeClient.
 */
class SequencedClaude implements ClaudeClient
{
    /** @var list<string> */
    public array $prompts = [];

    /** @param list<string> $responses */
    public function __construct(private array $responses) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        return $this->completeDetailed($prompt, $system)->text;
    }

    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
    {
        $i = count($this->prompts);
        $this->prompts[] = $prompt;
        $text = $this->responses[$i] ?? $this->responses[array_key_last($this->responses)];

        return new CompletionResult(text: $text, stopReason: 'end_turn');
    }
}

it('retries once on a budget overshoot, then keeps the corrected in-budget draft (report fix 1)', function () {
    $page = PageFixture::intakePage();
    $claim = proofIdFor($page->site_id);
    $overLong = trim(str_repeat('Endless on-demand hot water installed cleanly in one visit. ', 8)); // ~470 > 220
    $claude = new SequencedClaude([
        PageFixture::validResponse($claim, ['hero_subhead' => $overLong]), // overshoot
        PageFixture::validResponse($claim),                                 // corrected (~64 chars)
    ]);

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and(mb_strlen((string) $drafted->slot_payload['hero_subhead']))->toBeLessThanOrEqual(220)
        ->and($claude->prompts)->toHaveCount(2)                 // one bounded retry
        ->and($claude->prompts[1])->toContain('CORRECTION')     // the retry names the overshoot
        ->and($drafted->meta['slot_truncations'] ?? null)->toBeNull(); // retry fixed it → no truncation
});

it('truncates and publishes when the retry still overshoots — never leaves the page failed (report fix 1)', function () {
    Log::spy();
    $page = PageFixture::intakePage();
    $claim = proofIdFor($page->site_id);
    $overLong = trim(str_repeat('Endless on-demand hot water installed cleanly in one visit. ', 8));
    $claude = new SequencedClaude([
        PageFixture::validResponse($claim, ['hero_subhead' => $overLong]), // both attempts overshoot
    ]);

    $drafted = pageEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)      // NOT failed — a budget problem degrades
        ->and($drafted->hasDraft())->toBeTrue()
        ->and(mb_strlen((string) $drafted->slot_payload['hero_subhead']))->toBeLessThanOrEqual(220)
        ->and($drafted->meta['slot_truncations'])->toContain('hero_subhead');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx = []): bool => $msg === 'slot_truncated' && in_array('hero_subhead', $ctx['slots'] ?? [], true))
        ->atLeast()->once();
});
