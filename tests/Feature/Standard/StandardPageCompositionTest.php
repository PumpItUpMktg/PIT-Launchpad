<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftGuard;
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
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use App\PageBuilder\Validation\KitValidator;
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
function aboutPage(): Content
{
    $site = Site::factory()->create(['brand_name' => 'Lone Star Plumbing']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 2]);
    SiteBranding::factory()->create(['site_id' => $site->id]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Warranty,
        'payload' => ['label' => '10-year warranty'], 'is_substantiated' => true,
    ]);

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
        ->and($drafted->slot_payload['values'])->toHaveCount(3)
        ->and($drafted->meta['image_specs'])->not->toBeEmpty();

    // The drafter is told the page's intent ("About"), not the coarse "utility" page type.
    expect($claude->prompts[0])->toContain('"About" page');
});

it('resolves a kit only for the standard pages whose composer has shipped', function () {
    (new WireframeKitSeeder)->run();

    expect(StandardKit::isComposable(StandardPageType::About))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Home))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::Faq))->toBeTrue()
        ->and(StandardKit::isComposable(StandardPageType::WhyChooseUs))->toBeTrue()
        // not yet built — these stay held ("Not ready yet") until their composer lands
        ->and(StandardKit::isComposable(StandardPageType::Privacy))->toBeFalse()
        ->and(StandardKit::isComposable(StandardPageType::Contact))->toBeFalse()
        ->and(StandardKit::isComposable(StandardPageType::AreasWeServe))->toBeFalse();

    expect(StandardKit::resolve(StandardPageType::About)?->name)->toBe('about-page')
        ->and(StandardKit::resolve(StandardPageType::Privacy))->toBeNull();
});
