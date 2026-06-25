<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ProofEditor;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\User;
use Filament\Facades\Filament;
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
