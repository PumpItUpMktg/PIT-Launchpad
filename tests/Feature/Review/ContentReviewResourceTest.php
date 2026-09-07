<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\ContentReviewResource\Pages\ListContentReviews;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
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

test('the NearDuplicate flag names the specific post it duplicates (not a generic "possible duplicate")', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    CurrentSite::set($site->id); // the review queue is SiteScope-locked to the current tenant

    $original = Content::factory()->post()->create([
        'site_id' => $site->id, 'slug' => 'flooding-resources-hoboken',
        'status' => ContentStatus::Published, 'body' => '<p>x</p>',
    ]);
    // A held near-duplicate (§6a guard: in_review, pointing at the live post it duplicates).
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::InReview,
        'near_dup_of_content_id' => $original->id, 'body' => null,
    ]);

    Livewire::test(ListContentReviews::class)
        ->assertOk()
        ->assertSee('Duplicates /flooding-resources-hoboken'); // names the post, judge without opening both
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
