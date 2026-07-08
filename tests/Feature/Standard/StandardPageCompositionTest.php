<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftGuard;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageDraftingEngine;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Drafting\SlotShaper;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use App\PageBuilder\Validation\KitValidator;
use App\Pages\PageState;
use App\Pages\PageStatePresenter;
use App\Standard\StandardKit;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function standardEngine(FakeClaudeClient $claude): PageDraftingEngine
{
    return new PageDraftingEngine(
        new PageGroundingAssembler,
        new PageDrafter(new DraftCall($claude)),
        new DraftGuard,
        app(KitValidator::class),
        new SlotShaper,
    );
}

/** An undrafted About page wired to brand grounding + the about-page composer kit. */
function aboutPage(bool $withNarrative = true): Content
{
    $site = Site::factory()->create(['brand_name' => 'Lone Star Plumbing']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 2]);
    SiteBranding::factory()->create(['site_id' => $site->id]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => '10-year warranty'], 'is_substantiated' => true,
    ]);

    if ($withNarrative) {
        SiteNarrative::factory()->create([
            'site_id' => $site->id,
            'story' => 'Lone Star Plumbing started with one truck and a promise to show up on time.',
            'mission' => 'Make plumbing painless for local homeowners.',
            'values' => [['title' => 'On time', 'description' => 'Every visit.']],
        ]);
    }

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('name', 'about-page')->firstOrFail();

    return Content::factory()->page()->create([
        'site_id' => $site->id,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::About,
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => [],
    ]);
}

function aboutResponse(string $claimId): string
{
    return Draft::json([
        'slots' => [
            'hero_headline' => 'Plumbing You Can Count On',
            'our_story' => '<p>Lone Star Plumbing began with one truck and a simple promise: show up on time and do honest '
                .'work. Years later that promise still runs every job — the same care for a leaky faucet as a full repipe.</p>',
            'mission' => 'We exist to make plumbing painless for local homeowners — clear pricing, clean work, and real respect for your home.',
            'values' => ['Show up on time, every time', 'Quote before we start', 'Leave it cleaner than we found it'],
            'proof_points' => 'Every installation is backed by our written 10-year warranty and a licensed, background-checked crew.',
        ],
        'images' => [[
            'slot' => 'hero_image',
            'prompt' => 'A friendly plumber arriving at a front door with a service van',
            'seo_filename' => 'about-hero.jpg',
            'alt' => 'A plumber arriving at a home',
        ]],
        'claims_used' => [['text' => '10-year warranty', 'claim_id' => $claimId]],
    ]);
}

it('drafts a standard About page end-to-end → needs_review with its kit slots filled', function () {
    $page = aboutPage();
    $claimId = (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $page->site_id)->value('id');
    $claude = new FakeClaudeClient(aboutResponse($claimId));

    $drafted = standardEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and($drafted->hasDraft())->toBeTrue()
        ->and($drafted->slot_payload['hero_headline'])->toContain('Plumbing')
        ->and($drafted->slot_payload['values'])->toHaveCount(3)   // mission/values kept — intake present
        ->and($drafted->meta['image_specs'])->not->toBeEmpty();

    // The drafter is told the page's intent ("About") AND grounded on the captured narrative.
    expect($claude->prompts[0])->toContain('"About" page')
        ->and($claude->prompts[0])->toContain('BRAND NARRATIVE')
        ->and($claude->prompts[0])->toContain('show up on time'); // the captured story is injected
});

it('drafts an About page with only TWO captured values (renders what is captured, never blocks)', function () {
    // Regression: the values slot required min 3; a brand with 2 captured values made the page fail
    // the kit schema ("Slot [values] has 2 items, minimum 3") instead of rendering the 2 it has.
    $page = aboutPage();
    $claimId = (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $page->site_id)->value('id');
    $response = Draft::json([
        'slots' => [
            'hero_headline' => 'Plumbing You Can Count On',
            'our_story' => '<p>Lone Star Plumbing began with one truck and a simple promise: show up on time and do honest '
                .'work. Years later that same promise still runs every single job we take on, big or small.</p>',
            'mission' => 'We exist to make plumbing painless for local homeowners — clear pricing and clean work.',
            'values' => ['Show up on time, every time', 'Quote before we start'], // only two
        ],
        'images' => [['slot' => 'hero_image', 'prompt' => 'A plumber at a front door', 'seo_filename' => 'about.jpg', 'alt' => 'A plumber']],
        'claims_used' => [['text' => '10-year warranty', 'claim_id' => $claimId]],
    ]);

    $drafted = standardEngine(new FakeClaudeClient($response))->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and($drafted->hasDraft())->toBeTrue()
        ->and($drafted->slot_payload['values'])->toHaveCount(2); // the captured two render — no hard-fail
});

it('holds an About page with no captured story — needs intake, never fabricated', function () {
    $page = aboutPage(withNarrative: false)->fresh();

    // The honesty gate: required intake (story) absent → not generatable, surfaced as held-intake.
    expect(app(GroundingReadiness::class)->ready($page))->toBeFalse()
        ->and(app(PageStatePresenter::class)->resolve($page))->toBe(PageState::HeldIntake);
});

it('degrades by omission — drops optional intake slots whose intake was not captured', function () {
    // story captured (required satisfied) but NO mission/values → those slots must not be fabricated.
    $site = Site::factory()->create(['brand_name' => 'Lone Star Plumbing']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 1]);
    SiteBranding::factory()->create(['site_id' => $site->id]);
    SiteNarrative::factory()->create([
        'site_id' => $site->id, 'story' => 'One truck, one promise: on time, honest work.',
        'mission' => null, 'values' => null, 'differentiators' => null,
    ]);
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('name', 'about-page')->firstOrFail();
    $page = Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Utility, 'standard_type' => StandardPageType::About,
        'wireframe_kit_id' => $kit->id, 'slot_payload' => [],
    ]);

    // Even if the model emits mission/values, they're dropped (intake absent) — degrade by omission.
    $claude = new FakeClaudeClient(aboutResponse('x'));
    $drafted = standardEngine($claude)->draftPage($page->fresh());

    expect($drafted->hasDraft())->toBeTrue()
        ->and($drafted->slot_payload)->toHaveKey('our_story')
        ->and($drafted->slot_payload)->not->toHaveKey('mission')   // dropped — no captured mission
        ->and($drafted->slot_payload)->not->toHaveKey('values');   // dropped — no captured values
});

it('resolves a kit only for the standard pages whose composer has shipped', function () {
    (new WireframeKitSeeder)->run();

    expect(StandardKit::isComposable(StandardPageType::About))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Home))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Faq))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::WhyChooseUs))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::AreasWeServe))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Privacy))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Terms))->toBeTrue()
        // not yet built — these stay held ("Not ready yet") until their composer lands
        ->and(StandardKit::isComposable(StandardPageType::Contact))->toBeFalse();

    expect(StandardKit::resolve(StandardPageType::About)?->name)->toBe('about-page')
        ->and(StandardKit::resolve(StandardPageType::Contact))->toBeNull();
});
