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
            'hero_problem' => 'Slow or clogged drains across your home?',
            'hero_solution' => 'Every drain-cleaning service in one place — pick the job you need.',
            'hub_intro' => '<p>From kitchen sinks to main sewer lines, here is the full range of drain work we handle.</p>',
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
        ->and($drafted->slot_payload['hero_problem'])->toContain('drains')
        ->and($drafted->slot_payload['hero_solution'])->toContain('one place')
        ->and($drafted->meta['image_specs'])->not->toBeEmpty();
});
