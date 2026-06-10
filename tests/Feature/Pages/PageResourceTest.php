<?php

use App\Enums\UserRole;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Jobs\GeneratePage;
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

test('the Pages list mounts and Generate queues the job + marks generating', function () {
    Bus::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $page = PageFixture::intakePage();

    Livewire::test(ListPages::class)
        ->assertOk()
        ->callTableAction('generate', $page);

    Bus::assertDispatched(GeneratePage::class, fn (GeneratePage $job) => $job->contentId === $page->id);
    expect($page->fresh()->isGenerating())->toBeTrue();
});
