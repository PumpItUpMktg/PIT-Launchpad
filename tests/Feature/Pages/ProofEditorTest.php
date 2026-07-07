<?php

use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ProofEditor;
use App\Jobs\RenderImage;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
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
        'slot_payload' => ['hero_problem' => 'No hot water?', 'hero_solution' => 'Same-day install.'],
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
        ->call('startEdit', 'hero_problem')
        ->set('editValue', 'Cold showers again?')
        ->set('editReason', 'off_brand')
        ->call('saveEdit')
        ->assertSet('editingKey', null);

    expect($page->fresh()->slot_payload['hero_problem'])->toBe('Cold showers again?');
    // the §7 quality signal: the edit + its reason are persisted
    expect(ContentEdit::query()->where('content_id', $page->id)->where('reason', 'off_brand')->exists())->toBeTrue();
});

it('refuses to save an edit with no reason (signal stays honest)', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('startEdit', 'hero_problem')
        ->set('editValue', 'changed')
        ->call('saveEdit')              // no reason picked
        ->assertSet('editingKey', 'hero_problem'); // stays open

    expect(ContentEdit::query()->where('content_id', $page->id)->exists())->toBeFalse();
});

it('approves a drafted page (no WordPress contact)', function () {
    $page = draftedPage();

    Livewire::withQueryParams(['content' => $page->id])
        ->test(ProofEditor::class)
        ->call('approve');

    expect($page->fresh()->status)->toBe(ContentStatus::Approved);
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
