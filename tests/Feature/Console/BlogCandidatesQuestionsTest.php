<?php

use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Filament\Console\Pages\BlogCandidates;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->admin()->create());
});

it('shows a questions candidate with its demand tag and the refresh-suggested panel for a marked post', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'source_name' => 'questions', 'draft_trigger' => DraftTrigger::Question,
        'title' => 'How long do sump pumps last?', 'body' => null,
        'meta' => ['question' => ['query' => 'how long do sump pumps last', 'phrasings' => ['how long does a sump pump last'], 'impressions' => 140, 'clicks' => 3, 'position' => 9.0]],
    ]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-lifespan', 'title' => 'Sump pump lifespan', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => ['query' => 'when should i replace my sump pump', 'phrasings' => [], 'impressions' => 88, 'clicks' => 2, 'position' => 14.0, 'reason' => 'our post ranks #14.0 for it — refresh it', 'mined_at' => '2026-11-04T04:30:00+00:00']],
    ]);

    Livewire::test(BlogCandidates::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('How long do sump pumps last?')
        ->assertSee('Asked 140×')
        ->assertSee('how long does a sump pump last')
        ->assertSee('Refresh suggested')
        ->assertSee('when should i replace my sump pump')
        ->assertSee('Sump pump lifespan')
        ->assertSee('pos 14.0');
});
