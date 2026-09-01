<?php

use App\Filament\Console\Pages\PhotoLibrary;
use App\Models\Account;
use App\Models\LibraryPhoto;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create())); // Operator (Super Admin) by default

it('is operator-gated', function (): void {
    expect(PhotoLibrary::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->client()->create());
    expect(PhotoLibrary::canAccess())->toBeFalse();
})->skip(fn () => ! method_exists(User::factory(), 'client'), 'no client factory state');

it('uploads photos into the account library', function (): void {
    Storage::fake('r2');
    Filament::setCurrentPanel('console');
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();

    Livewire::test(PhotoLibrary::class)
        ->set('siteId', $site->id)
        ->set('uploads', [UploadedFile::fake()->image('kitchen.jpg', 24, 18)])
        ->call('upload')
        ->assertHasNoErrors();

    $photo = LibraryPhoto::query()->where('account_id', $account->id)->first();
    expect($photo)->not->toBeNull()
        ->and($photo->width)->toBe(24)
        ->and($photo->height)->toBe(18);
    Storage::disk('r2')->assertExists($photo->r2_key);
});

it('tags, labels, and deletes a library photo', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $photo = LibraryPhoto::factory()->create(['account_id' => $account->id]);

    $page = new PhotoLibrary;
    $page->siteId = $site->id;

    $page->startEdit($photo->id);
    $page->editLabel = 'Kitchen reno';
    $page->editTags = 'kitchen, plumbing, kitchen';
    $page->saveEdit();

    $photo->refresh();
    expect($photo->label)->toBe('Kitchen reno')
        ->and($photo->tags)->toBe(['kitchen', 'plumbing']);   // trimmed + deduped

    $page->delete($photo->id);
    expect(LibraryPhoto::query()->find($photo->id))->toBeNull(); // soft-deleted
});

it('lists only the working account\'s photos, filtered by tag', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    LibraryPhoto::factory()->create(['account_id' => $account->id, 'tags' => ['kitchen']]);
    LibraryPhoto::factory()->create(['account_id' => $account->id, 'tags' => ['roof']]);
    LibraryPhoto::factory()->create(); // a different account — must never appear

    $page = new PhotoLibrary;
    $page->siteId = $site->id;

    expect($page->getPhotosProperty())->toHaveCount(2);

    $page->filterTag = 'kitchen';
    expect($page->getPhotosProperty())->toHaveCount(1);
});
