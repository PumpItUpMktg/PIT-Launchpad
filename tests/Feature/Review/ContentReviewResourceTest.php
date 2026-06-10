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

test('an undrafted borderline row offers Generate and hides Publish now', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    // §6a routes borderline relevance straight to in_review — undrafted (no body).
    $undrafted = Content::factory()->post()->create(['status' => ContentStatus::InReview, 'body' => null]);

    Livewire::test(ListContentReviews::class)
        ->assertOk()
        ->assertTableActionVisible('generate', $undrafted)
        ->assertTableActionHidden('publish_now', $undrafted);
});

test('a drafted row hides Generate and offers Publish now', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $drafted = Content::factory()->post()->create(['status' => ContentStatus::NeedsReview]); // post() sets a body

    Livewire::test(ListContentReviews::class)
        ->assertTableActionHidden('generate', $drafted)
        ->assertTableActionVisible('publish_now', $drafted);
});
