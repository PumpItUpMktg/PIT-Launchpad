<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Overview;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Models\Content;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

test('the overview is the panel landing (slug /)', function () {
    expect(Overview::getUrl())->toBe(Filament::getPanel('admin')->getUrl());
});

test('a card per site renders with the New site on-ramp', function () {
    Site::factory()->create(['brand_name' => 'Alpha', 'status' => SiteStatus::Live]);
    Site::factory()->create(['brand_name' => 'Beta', 'status' => SiteStatus::Onboarding]);

    Livewire::test(Overview::class)
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('New site');
});

test('a setup card resumes the wizard at its step; a live card opens Grow', function () {
    $live = Site::factory()->create(['brand_name' => 'LiveCo', 'status' => SiteStatus::Live]);
    $onb = Site::factory()->create(['brand_name' => 'OnbCo', 'status' => SiteStatus::Onboarding]);
    SetupState::query()->create(['site_id' => $onb->id, 'current_step' => 5]); // Structure (5 of 7)

    $cards = collect(Livewire::test(Overview::class)->instance()->sites);

    $liveCard = $cards->firstWhere('id', $live->id);
    $onbCard = $cards->firstWhere('id', $onb->id);

    expect($liveCard['mode'])->toBe('live')
        ->and($liveCard['url'])->toBe(Grow::getUrl(['site' => $live->id]))
        ->and($onbCard['mode'])->toBe('setup')
        ->and($onbCard['pct'])->toBe((int) round(5 / 7 * 100))             // ~71%
        ->and($onbCard['resume'])->toContain('Step 5 of 7')
        ->and($onbCard['url'])->toBe(Structure::getUrl(['site' => $onb->id])); // resume at step 5
});

test('triage floats attention up — a failing live site ranks above a calm one regardless of name', function () {
    $failing = Site::factory()->create(['brand_name' => 'Zeta', 'status' => SiteStatus::Active]);
    Content::factory()->create(['site_id' => $failing->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::PublishFailed, 'slug' => 'z1', 'title' => 'Z1']);
    $calm = Site::factory()->create(['brand_name' => 'Aaa', 'status' => SiteStatus::Active]);

    $cards = collect(Livewire::test(Overview::class)->instance()->sites);
    $fail = $cards->firstWhere('id', $failing->id);
    $ok = $cards->firstWhere('id', $calm->id);

    expect($fail['work'])->toContain('need attention')
        ->and($fail['sort'])->toBe(0)
        ->and($ok['work'])->toBe('All caught up')
        ->and($ok['sort'])->toBe(3)
        // Zeta (failing) sorts before Aaa (calm) despite the alphabet.
        ->and($cards->search(fn ($c) => $c['id'] === $failing->id))
        ->toBeLessThan($cards->search(fn ($c) => $c['id'] === $calm->id));
});

test('an active (launched) site routes to Grow with build progress, never back into the wizard', function () {
    $site = Site::factory()->create(['brand_name' => 'BuiltCo', 'status' => SiteStatus::Active]);
    SetupState::query()->create([
        'site_id' => $site->id, 'current_step' => 9,
        'services_done' => true, 'deps_ready' => true, 'brand_pushed' => true, 'territory_done' => true,
        'structure_finalized' => true, 'inventory_reviewed' => true, 'approved' => true, 'launched' => true,
    ]);
    // 2 materialized pages, 1 published → "Active · 1/2"
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'title' => 'Home', 'slug' => 'home']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Candidate, 'title' => 'About', 'slug' => 'about']);

    $card = collect(Livewire::test(Overview::class)->instance()->sites)->firstWhere('id', $site->id);

    expect($card['onboarding'])->toBeFalse()
        ->and($card['url'])->toBe(Grow::getUrl(['site' => $site->id]))
        ->and($card['pages'])->toBe(['published' => 1, 'total' => 2]);
});

test('a handed-over (Live) site also opens Grow — the per-site surface is Grow, not a separate cockpit', function () {
    $site = Site::factory()->create(['brand_name' => 'LiveHO', 'status' => SiteStatus::Live]);

    $card = collect(Livewire::test(Overview::class)->instance()->sites)->firstWhere('id', $site->id);

    expect($card['mode'])->toBe('live')
        ->and($card['url'])->toBe(Grow::getUrl(['site' => $site->id]));
});

test('the empty state invites the first site instead of a sad grid', function () {
    Livewire::test(Overview::class)->assertOk()->assertSee('Add your first site');
});

test('the New site button points at the single create on-ramp', function () {
    expect(Livewire::test(Overview::class)->instance()->newSiteUrl())->toBe(CreateSite::getUrl());
});
