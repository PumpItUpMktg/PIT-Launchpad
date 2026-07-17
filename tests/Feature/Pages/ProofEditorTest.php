<?php

use App\ContentEngine\Review\ProofPreview;
use App\Enums\ContentStatus;
use App\Enums\MediaKind;
use App\Enums\RenderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ProofEditor;
use App\Jobs\RenderImage;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\MediaAsset;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\User;
use App\Publishing\PagePreviewService;
use App\Publishing\PreviewResult;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PageFixture;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function draftedPage(): Content
{
    return PageFixture::intakePage([
        'status' => ContentStatus::NeedsReview,
        'slot_payload' => ['hero_headline' => 'No hot water?', 'hero_subhead' => 'Same-day install.'],
        'meta' => ['seo' => ['title' => 'Tankless Install', 'meta_description' => 'Endless hot water.']],
    ]);
}

it('renders the structured preview (sections, SEO, strategy rail)', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->assertOk()
        ->assertSee('No hot water?')        // real copy from a section
        ->assertSee('Tankless Install')     // SEO search-appearance strip
        ->assertSee('Placement');           // strategy rail
});

it('captures a one-tap reason when a block is corrected in place', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('startEdit', 'hero_headline')
        ->set('editValue', 'Cold showers again?')
        ->set('editReason', 'off_brand')
        ->call('saveEdit')
        ->assertSet('editingKey', null);

    expect($page->fresh()->slot_payload['hero_headline'])->toBe('Cold showers again?');
    // the §7 quality signal: the edit + its reason are persisted
    expect(ContentEdit::query()->where('content_id', $page->id)->where('reason', 'off_brand')->exists())->toBeTrue();
});

it('refuses to save an edit with no reason (signal stays honest)', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('startEdit', 'hero_headline')
        ->set('editValue', 'changed')
        ->call('saveEdit')              // no reason picked
        ->assertSet('editingKey', 'hero_headline'); // stays open

    expect(ContentEdit::query()->where('content_id', $page->id)->exists())->toBeFalse();
});

it('approves a drafted page (no WordPress contact)', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('approve');

    expect($page->fresh()->status)->toBe(ContentStatus::Approved);
});

it('surfaces the full-page preview URL from the preview-push', function () {
    $page = draftedPage();

    $service = Mockery::mock(PagePreviewService::class);
    $service->shouldReceive('preview')->once()
        ->andReturn(PreviewResult::ready(42, 'https://wp.example/?p=42&preview=true'));
    app()->instance(PagePreviewService::class, $service);

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->assertSee('Preview full page')
        ->call('previewFullPage')
        ->assertSet('previewUrl', 'https://wp.example/?p=42&preview=true')
        ->assertSee('Open full page ↗');
});

it('is operator-only', function () {
    expect(ProofEditor::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ProofEditor::canAccess())->toBeFalse();
});

it('regenerates one image slot — resets its render job, re-dispatches, and captures the edit', function () {
    Bus::fake();
    $page = draftedPage();
    $job = RenderJob::factory()->create([
        'site_id' => $page->site_id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'status' => RenderStatus::Succeeded, 'r2_key' => 'sites/x/hero.webp', 'attempts' => 1,
    ]);

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('regenerateImage', 'hero_image');

    $fresh = RenderJob::withoutGlobalScope(SiteScope::class)->find($job->id);
    expect($fresh->status)->toBe(RenderStatus::Queued)
        ->and($fresh->attempts)->toBe(0);

    Bus::assertDispatched(RenderImage::class, fn ($j) => $j->renderJobId === $job->id);
    expect(ContentEdit::query()->where('content_id', $page->id)->where('field', 'image:hero_image')->exists())->toBeTrue();
});

it('replaces an image slot with an upload — stores to R2, writes the render job, captures the edit', function () {
    Storage::fake('r2');
    $page = draftedPage();
    $job = RenderJob::factory()->create([
        'site_id' => $page->site_id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'status' => RenderStatus::Succeeded, 'r2_key' => 'sites/x/old.webp',
    ]);

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('startReplace', 'hero_image')
        ->set('imageUpload', UploadedFile::fake()->image('photo.jpg', 800, 600));

    $fresh = RenderJob::withoutGlobalScope(SiteScope::class)->find($job->id);
    expect($fresh->r2_key)->not->toBe('sites/x/old.webp')                 // the upload replaced it
        ->and($fresh->status)->toBe(RenderStatus::Succeeded);   // ready, no re-render
    Storage::disk('r2')->assertExists($fresh->r2_key);
    expect(ContentEdit::query()->where('content_id', $page->id)->where('field', 'image:hero_image')->exists())->toBeTrue();
});

it('chooses an existing library image for a slot — writes the render job and captures the edit', function () {
    $page = draftedPage();
    $asset = MediaAsset::factory()->create([
        'site_id' => $page->site_id, 'kind' => MediaKind::Photo,
        'r2_key' => 'sites/x/lib.webp', 'alt_text' => 'Our crew',
    ]);
    $job = RenderJob::factory()->create([
        'site_id' => $page->site_id, 'content_id' => $page->id, 'slot' => 'hero_image',
        'status' => RenderStatus::Succeeded, 'r2_key' => 'sites/x/old.webp',
    ]);

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('chooseFromLibrary', 'hero_image', $asset->id);

    $fresh = RenderJob::withoutGlobalScope(SiteScope::class)->find($job->id);
    expect($fresh->r2_key)->toBe('sites/x/lib.webp')
        ->and($fresh->alt)->toBe('Our crew');
    expect(ContentEdit::query()->where('content_id', $page->id)->where('field', 'image:hero_image')->exists())->toBeTrue();
});

it('renders rich-text body + FAQ answers as HTML (links live), not escaped markup', function () {
    $page = PageFixture::intakePage([
        'status' => ContentStatus::NeedsReview,
        'slot_payload' => [
            'hero_headline' => 'Waterproofing',
            'svc_intro' => '<p>Start with our <a href="/basement-waterproofing-cost-guide">Cost Guide</a> — it explains what drives price.</p>',
            'faq' => [
                ['question' => 'How much does it cost?', 'answer' => 'It depends — see the <a href="/cost-guide">guide</a>. <script>alert(1)</script>'],
            ],
        ],
    ]);

    // The read model renders the HTML (sanitized) instead of handing back raw markup to escape.
    $sections = collect(app(ProofPreview::class)->for($page->fresh())['sections'])->keyBy('key');
    expect($sections['svc_intro']['html'])->toContain('<a href="/basement-waterproofing-cost-guide">Cost Guide</a>')
        ->and($sections['faq']['faq'][0]['answer'])->toContain('<a href="/cost-guide">guide</a>')
        ->and($sections['faq']['faq'][0]['answer'])->not->toContain('<script>'); // sanitized

    // The preview surface shows the link as a real element, never escaped "&lt;a".
    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->assertOk()
        ->assertSeeHtml('<a href="/basement-waterproofing-cost-guide">')
        ->assertSeeHtml('<a href="/cost-guide">')
        ->assertDontSee('&lt;a href'); // no raw/escaped tags leaking through
});
