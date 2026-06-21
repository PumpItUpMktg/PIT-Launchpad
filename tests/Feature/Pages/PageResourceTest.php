<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Jobs\GeneratePage;
use App\Models\Content;
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

test('a planned, kit-bound page shows Build, which queues single-page generation', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage(); // has a service kit → buildable

    Livewire::test(ListPages::class)
        ->assertOk()
        ->assertTableActionVisible('build', $page)
        ->assertTableActionHidden('composer_pending', $page)
        ->callTableAction('build', $page);

    Bus::assertDispatched(GeneratePage::class, fn (GeneratePage $job) => $job->contentId === $page->id);
    expect($page->fresh()->isGenerating())->toBeTrue();
});

test('a planned page with no composer (no kit) shows "composer pending", never Build', function () {
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
        ->assertTableActionHidden('build', $page)            // never fakes a build
        ->assertTableActionVisible('composer_pending', $page);
});
