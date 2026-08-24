<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\UserRole;
use App\Filament\Resources\AiContentResource;
use App\Filament\Resources\AiContentResource\Pages\ListAiContent;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Filament\Resources\ContentReviewResource\Pages\ListContentReviews;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

function geoPost(Site $site, string $status, string $title, string $lane = Content::GEO_LANE): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'intake_type' => IntakeType::Directed,
        'status' => $status,
        'title' => $title,
        'slug' => Str::slug($title),
        'draft_lane' => $lane,
        'version' => 1,
    ]);
}

it('lists GEO-lane candidates and review items for an operator', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    $candidate = geoPost($site, ContentStatus::Candidate->value, 'Geo candidate');
    $review = geoPost($site, ContentStatus::NeedsReview->value, 'Geo needs review');
    $published = geoPost($site, ContentStatus::Published->value, 'Geo published'); // drops off (header tally only)
    $blogCandidate = geoPost($site, ContentStatus::Candidate->value, 'Blog candidate', lane: 'reactive');

    Livewire::test(ListAiContent::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$candidate, $review])
        ->assertCanNotSeeTableRecords([$published, $blogCandidate]);
});

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));

    expect(AiContentResource::canAccess())->toBeFalse();
});

it('pulls GEO-lane items OUT of the Local Blog Candidates queue', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    $geo = geoPost($site, ContentStatus::Candidate->value, 'Geo candidate');
    $blog = geoPost($site, ContentStatus::Candidate->value, 'Blog candidate', lane: 'reactive');
    $nullLane = geoPost($site, ContentStatus::Candidate->value, 'Legacy candidate', lane: '');
    // A truly null draft_lane must still show in the blog queue.
    $nullLane->forceFill(['draft_lane' => null])->save();

    Livewire::test(ListCandidates::class)
        ->assertCanSeeTableRecords([$blog, $nullLane])
        ->assertCanNotSeeTableRecords([$geo]);
});

it('pulls GEO-lane drafts OUT of the blog Review queue', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    $geo = geoPost($site, ContentStatus::NeedsReview->value, 'Geo draft');
    $blog = geoPost($site, ContentStatus::NeedsReview->value, 'Blog draft', lane: 'reactive');

    Livewire::test(ListContentReviews::class)
        ->assertCanSeeTableRecords([$blog])
        ->assertCanNotSeeTableRecords([$geo]);
});
