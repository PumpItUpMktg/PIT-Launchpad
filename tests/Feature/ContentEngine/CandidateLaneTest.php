<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('shows only POST candidates — §4 page pillars do not leak into the Generate-post lane', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'status' => ContentStatus::Candidate, 'title' => 'News Post Candidate',
    ]);
    // A §4 pillar stub — kind=page, status=candidate. Before the lane filter this
    // appeared here and "Generate post" flipped it to a post (blog template).
    $pillar = Content::factory()->page()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'status' => ContentStatus::Candidate, 'page_type' => PageType::Service,
        'title' => 'Service Pillar Page',
    ]);

    Livewire::test(ListCandidates::class)
        ->assertCanSeeTableRecords([$post])
        ->assertCanNotSeeTableRecords([$pillar]);
});
