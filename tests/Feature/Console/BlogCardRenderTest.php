<?php

use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogCandidates;
use App\Filament\Console\Pages\BlogPublish;
use App\Filament\Console\Pages\BlogReview;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->admin()->create());
});

it('renders the three blog pipeline pages with a populated card', function () {
    $site = Site::factory()->create();
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Radon candidate', 'body' => '<p>An educational body.</p>', 'relevance_score' => 0.91]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Review draft', 'body' => '<p>A drafted body.</p>']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Ready post', 'body' => '<p>A ready body.</p>'])
        ->releaseToPublish(); // released → shows on the Publish page

    Livewire::test(BlogCandidates::class)->set('siteId', $site->id)
        ->assertOk()->assertSee('Radon candidate')->assertSee('Score');
    Livewire::test(BlogReview::class)->set('siteId', $site->id)
        ->assertOk()->assertSee('Review draft');
    Livewire::test(BlogPublish::class)->set('siteId', $site->id)
        ->assertOk()->assertSee('Ready post');
});
