<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\ContentReviewResource\Pages\ListContentReviews;
use App\Models\Content;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('only operators can access the review queue', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ContentReviewResource::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ContentReviewResource::canAccess())->toBeFalse();
});

test('the review queue list page mounts and renders for an operator', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Content::factory()->create(['status' => ContentStatus::NeedsReview]);

    Livewire::test(ListContentReviews::class)->assertOk();
});
