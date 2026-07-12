<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Targeting;
use App\Filament\Resources\BlogTargetResource;
use App\Filament\Resources\CandidateResource;
use App\Filament\Resources\ContentReviewResource;
use App\Filament\Resources\KeywordResource;
use App\Filament\Resources\SiloManagementResource;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\TargetingBoard;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('builds one card per silo — queue-ordered targets, covered/gap split, thin flag, unassigned band', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);

    $covered = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump installation',
        'opportunity_score' => 40, 'priority' => 0,
        'target_content_id' => Content::factory()->create(['site_id' => $site->id])->id,
    ]);
    $promoted = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'battery backup sump pump',
        'opportunity_score' => 10, 'priority' => 3, 'target_content_id' => null,
    ]);
    $orphan = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => null, 'query' => 'crawl space mold',
        'opportunity_score' => 5, 'priority' => 0, 'target_content_id' => null,
    ]);

    $board = app(TargetingBoard::class)->for($site);

    expect($board['silos'])->toHaveCount(1);
    $card = $board['silos'][0];
    // Operator priority outranks raw opportunity — the promoted gap leads the card.
    expect(collect($card['keywords'])->pluck('id')->all())->toBe([$promoted->id, $covered->id])
        ->and($card['covered'])->toBe(1)
        ->and($card['gaps'])->toBe(1)
        // One keyword of support sits below the §4 viability floor → flagged, with the reason.
        ->and($card['viable'])->toBeFalse()
        ->and($card['warning'])->toContain('Thin silo')
        // Keywords no silo claims surface in the unassigned band — nothing hides.
        ->and(collect($board['unassigned'])->pluck('id')->all())->toBe([$orphan->id]);
});

it('renders the cards and promote bumps the priority override (owned keywords only)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $kw = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump installation',
        'opportunity_score' => 40, 'priority' => 0, 'target_content_id' => null,
    ]);
    $foreign = Keyword::factory()->create(['priority' => 0]); // another tenant's keyword
    session(['guided_site_id' => $site->id]);

    Livewire::test(Targeting::class)
        ->assertOk()
        ->assertSee('Sump Pumps')
        ->assertSee('sump pump installation')
        ->call('promote', $kw->id)
        ->call('promote', $foreign->id); // silently refused — not this site's row

    expect($kw->fresh()->priority)->toBe(1)
        ->and($foreign->fresh()->priority)->toBe(0);
});

it('the reorganized menu: Grow standalone, Local Blog group, Targeting cards primary, tables demoted', function () {
    // Grow is its own top-level entry — the pages workbench, clear of the blog pipeline.
    expect(Grow::shouldRegisterNavigation())->toBeTrue()
        ->and(Grow::getNavigationGroup())->toBeNull()
        // The news/relevance pipeline is the Local Blog group.
        ->and(ContentReviewResource::getNavigationGroup())->toBe('Local Blog')
        ->and(CandidateResource::getNavigationGroup())->toBe('Local Blog')
        ->and(BlogTargetResource::getNavigationGroup())->toBe('Local Blog')
        // Targeting: the silo-cards page leads; the flat tables leave the nav (routes kept).
        ->and(Targeting::shouldRegisterNavigation())->toBeTrue()
        ->and(KeywordResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SiloManagementResource::shouldRegisterNavigation())->toBeFalse();
});
