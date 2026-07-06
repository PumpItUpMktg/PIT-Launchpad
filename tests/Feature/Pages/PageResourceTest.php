<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Jobs\GeneratePage;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\PageFixture;

test('only operators can access the Pages resource', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(PageResource::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(PageResource::canAccess())->toBeFalse();
});

test('a planned, kit-bound page shows Generate (not Build), which queues single-page generation', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(); // has a service kit → buildable

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionVisible('generate', $page)
        ->assertTableActionHidden('composer_pending', $page)
        ->assertTableActionHidden('publish', $page)         // nothing to publish until approved
        ->callTableAction('generate', $page);

    Bus::assertDispatched(GeneratePage::class, fn (GeneratePage $job) => $job->contentId === $page->id);
    expect($page->fresh()->isGenerating())->toBeTrue()
        ->and($page->fresh()->buildStateLabel())->toBe('Generating…');
});

test('a planned page with no composer (no kit) shows "composer pending", never Generate', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Utility, // standard/util — no kit at materialize
        'wireframe_kit_id' => null,
        'slot_payload' => [],
        'title' => 'About',
        'slug' => 'about',
    ]);

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionHidden('generate', $page)         // never fakes a build
        ->assertTableActionVisible('composer_pending', $page);

    expect($page->buildStateLabel())->toBe('Ready to generate');
});

test('an approved page shows Publish (the compose-and-push), which enqueues PublishContent', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(['status' => ContentStatus::Approved, 'slot_payload' => ['hero_problem' => 'x']]);

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionVisible('publish', $page)
        ->assertTableActionHidden('generate', $page)
        ->assertTableActionHidden('review', $page)           // morphs past Review once approved
        ->callTableAction('publish', $page);

    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $page->id);
    expect($page->buildStateLabel())->toBe('Approved · ready to publish');
});

test('a drafted page shows Regenerate (not Generate), which re-queues a fresh draft', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(['status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero_problem' => 'x']]);

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionHidden('generate', $page)      // already drafted — Generate morphs to Regenerate
        ->assertTableActionVisible('regenerate', $page)
        ->callTableAction('regenerate', $page);

    Bus::assertDispatched(GeneratePage::class, fn (GeneratePage $job) => $job->contentId === $page->id);
    // The generating marker is stamped (prior draft-error cleared); the row keeps its existing draft
    // visible until the worker overwrites it — a re-draft doesn't blank the page.
    expect($page->fresh()->meta['generating_at'] ?? null)->not->toBeNull();
});

test('a published page shows Re-push, which re-dispatches PublishContent (recreates a deleted WP page)', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(['status' => ContentStatus::Published, 'slot_payload' => ['hero_problem' => 'x']]);

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionVisible('repush', $page)
        ->callTableAction('repush', $page);

    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $page->id);
});

test('Re-push is hidden until a page has been published (nothing to re-push)', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(['status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero_problem' => 'x']]);

    Livewire::test(ListPages::class)->assertTableActionHidden('repush', $page);
});

test('Delete soft-deletes the page from Launchpad (recoverable, WP untouched)', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(['status' => ContentStatus::Published, 'slot_payload' => ['hero_problem' => 'x']]);

    Livewire::test(ListPages::class)->callTableAction('deletePage', $page);

    expect(Content::withoutGlobalScope(SiteScope::class)->whereKey($page->id)->exists())->toBeFalse()
        ->and(Content::withoutGlobalScope(SiteScope::class)->withTrashed()->whereKey($page->id)->exists())->toBeTrue();
});

test('bulk Publish queues a compose-and-push only for approved selections', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $approved = PageFixture::intakePage(['status' => ContentStatus::Approved, 'slot_payload' => ['hero_problem' => 'x']]);
    $draft = Content::factory()->page()->create(['site_id' => $approved->site_id, 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero_problem' => 'y'], 'slug' => 'svc-2']);

    Livewire::test(ListPages::class)
        ->callTableBulkAction('bulkPublish', [$approved, $draft]);

    // only the approved page dispatches; the draft is skipped (approve first)
    Bus::assertDispatchedTimes(PublishContent::class, 1);
    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $approved->id);
});
