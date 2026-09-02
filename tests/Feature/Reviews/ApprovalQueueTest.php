<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ReviewCaptureResource;
use App\Filament\Resources\ReviewCaptureResource\Pages\ListReviews;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Location;
use App\Models\Review;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Reviews\Approval\ReviewApprovalActions;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('approve publishes the review and re-enqueues its location + service pages', function (): void {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $service = Service::factory()->for($site)->create();
    $review = Review::factory()->for($site)->create(['location_id' => $location->id, 'needs_location' => false, 'status' => ReviewStatus::Pending]);
    $review->services()->attach($service->id);

    $locationPage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => $location->id]);
    $servicePage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'primary_service_id' => $service->id]);

    expect(app(ReviewApprovalActions::class)->approve($review, 'user-1'))->toBeTrue();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Published)
        ->and($review->published_at)->not->toBeNull()
        ->and($review->approved_by)->toBe('user-1');
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j): bool => $j->contentId === (string) $locationPage->id);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j): bool => $j->contentId === (string) $servicePage->id);
});

test('a needs_location review cannot be approved until it is reassigned', function (): void {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $review = Review::factory()->for($site)->create(['location_id' => null, 'needs_location' => true, 'status' => ReviewStatus::Pending]);

    expect(app(ReviewApprovalActions::class)->approve($review))->toBeFalse()
        ->and($review->fresh()->status)->toBe(ReviewStatus::Pending);
    Queue::assertNothingPushed();

    // Reassign clears the flag, then approve works.
    $location = Location::factory()->for($site)->create();
    app(ReviewApprovalActions::class)->reassignLocation($review, (string) $location->id);
    $review->refresh();
    expect($review->needs_location)->toBeFalse()
        ->and(app(ReviewApprovalActions::class)->approve($review))->toBeTrue();
});

test('reject, unpublish, saveBody and service cap behave', function (): void {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $review = Review::factory()->for($site)->create(['location_id' => $location->id, 'status' => ReviewStatus::Published, 'published_at' => now()]);
    $actions = app(ReviewApprovalActions::class);

    $actions->unpublish($review);
    expect($review->fresh()->status)->toBe(ReviewStatus::Approved)->and($review->fresh()->published_at)->toBeNull();

    $actions->reject($review);
    expect($review->fresh()->status)->toBe(ReviewStatus::Rejected);

    $actions->saveBody($review, '  Edited body text.  ');
    expect($review->fresh()->body)->toBe('Edited body text.');

    $services = Service::factory()->count(5)->for($site)->create();
    $actions->setServices($review, $services->pluck('id')->map('strval')->all());
    expect($review->fresh()->services()->count())->toBe(Review::MAX_SERVICES); // capped at 3
});

test('the queue is operator-gated and the approve table action publishes', function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ReviewCaptureResource::canAccess())->toBeTrue();

    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $review = Review::factory()->for($site)->create(['location_id' => $location->id, 'needs_location' => false, 'status' => ReviewStatus::Pending]);

    Livewire::test(ListReviews::class)
        ->assertOk()
        ->callTableAction('approve', $review);

    expect($review->fresh()->status)->toBe(ReviewStatus::Published);
});

test('a client cannot access the review queue', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ReviewCaptureResource::canAccess())->toBeFalse();
});
