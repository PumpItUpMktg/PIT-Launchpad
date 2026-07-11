<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftGuard;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageDraftingEngine;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Drafting\SlotShaper;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use App\PageBuilder\Validation\KitValidator;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function hubEngine(FakeClaudeClient $claude): PageDraftingEngine
{
    return new PageDraftingEngine(
        new PageGroundingAssembler,
        new PageDrafter(new DraftCall($claude)),
        new DraftGuard,
        app(KitValidator::class),
        new SlotShaper,
    );
}

/** An undrafted hub page wired to a silo whose services ground it. */
function hubPage(): Content
{
    $site = Site::factory()->create(['brand_name' => 'Sewer Gurus']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 1]);
    SiteBranding::factory()->create(['site_id' => $site->id]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Hydro Jetting']);
    $service->silos()->attach($silo->id);

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('name', 'hub-page')->firstOrFail();

    return Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Hub,
        'status' => ContentStatus::Candidate,
        'title' => 'Drain Cleaning',
        'slug' => 'drain-cleaning',
        'wireframe_kit_id' => $kit->id,
        'wireframe_kit_version' => $kit->version,
        'slot_payload' => [],
    ]);
}

function hubResponse(): string
{
    return Draft::json([
        'slots' => [
            'hero_headline' => 'Drain cleaning services',
            'hero_subhead' => 'Every drain-cleaning service in one place — pick the job you need.',
            'hub_intro' => '<p>From kitchen sinks and tub drains to the main sewer line, here is the full range of drain work we handle — '
                .'diagnosis, clearing, and the preventive jetting that keeps the line from clogging again.</p>',
            'hub_why' => '<p>A slow drain rarely fixes itself — left alone it becomes a full blockage, and a blocked main line backs up into the house.</p>',
            'faq' => [
                ['question' => 'Which drain service do I need?', 'answer' => 'Describe the symptom and we will point you to the right fix.'],
                ['question' => 'Do you camera-inspect first?', 'answer' => 'When the symptom suggests the main line, yes.'],
                ['question' => 'Is hydro jetting safe for old pipes?', 'answer' => 'We assess the line first and pick the method that fits.'],
            ],
        ],
        'images' => [[
            'slot' => 'hero_image',
            'prompt' => 'A plumber clearing a residential drain',
            'seo_filename' => 'drain-cleaning-hero.jpg',
            'alt' => 'A plumber clearing a residential drain',
        ]],
    ]);
}

it('grounds a hub page on its silo services (ready to draft)', function () {
    $page = hubPage();

    expect(app(GroundingReadiness::class)->ready($page->fresh()))->toBeTrue();
});

it('drafts a hub page end-to-end → needs_review with its kit slots filled', function () {
    $page = hubPage();
    $claude = new FakeClaudeClient(hubResponse());

    $drafted = hubEngine($claude)->draftPage($page->fresh());

    expect($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and($drafted->hasDraft())->toBeTrue()
        ->and($drafted->slot_payload['hero_headline'])->toContain('Drain')
        ->and($drafted->slot_payload['hero_subhead'])->toContain('one place')
        ->and($drafted->meta['image_specs'])->not->toBeEmpty();
});
