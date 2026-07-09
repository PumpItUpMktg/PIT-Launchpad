<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Grow;
use App\Guided\GrowDashboard;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Jobs\BuildStructure;
use App\Jobs\GeneratePage;
use App\Jobs\SyncSiloCategories;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

/** Mark the site launched so the Grow page's gate lets it mount (and re-render on actions). */
function growLaunched(Site $site): void
{
    SetupState::query()->create([
        'site_id' => $site->id, 'current_step' => 6,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true, 'launched' => true,
    ]);
}

test('Step 4 persists build config, materializes the manifest, and hands off to Grow (Onboarding → Active)', function () {
    $this->site->update(['status' => SiteStatus::Onboarding]);
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);
    SiloBlueprint::factory()->create(['site_id' => $this->site->id]); // a structure to plan over

    Livewire::test(Approve::class)
        ->set('localize', false)
        ->set('townPagePace', 8)
        ->set('freshContent', false)
        ->call('approveAndBuild')
        ->assertRedirect(Grow::getUrl());

    $state = SetupState::query()->where('site_id', $this->site->id)->first();
    expect($state->approved)->toBeTrue()
        ->and($state->launched)->toBeTrue()           // handoff fires at materialize-complete
        ->and($state->build_status)->toBe('live')
        ->and($state->localize)->toBeFalse()
        ->and($state->town_page_pace)->toBe(8)
        ->and($state->fresh_content)->toBeFalse()
        ->and($this->site->fresh()->status)->toBe(SiteStatus::Active)
        ->and(BuildPage::query()->where('site_id', $this->site->id)->count())->toBe(6); // fixed core manifest
    // materialized into one planned page per manifest entry (no AI)
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->where('kind', ContentKind::Page->value)->count())->toBe(6);
});

test('Finalize projects the silo tree to WP categories (queued, same trigger as materialize)', function () {
    Queue::fake();
    $this->site->update(['status' => SiteStatus::Onboarding]);
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);
    SiloBlueprint::factory()->create(['site_id' => $this->site->id]);

    Livewire::test(Approve::class)->call('approveAndBuild');

    Queue::assertPushed(SyncSiloCategories::class, fn ($job) => $job->siteId === $this->site->id);
});

test('the Grow dashboard counts live / building / planned from the page set', function () {
    // page-based: header strip and the workbench list derive from the same kind=page Content
    Content::factory()->page()->create(['site_id' => $this->site->id, 'status' => ContentStatus::Published, 'slot_payload' => ['h' => 'x']]);
    Content::factory()->page()->create(['site_id' => $this->site->id, 'status' => ContentStatus::Rendering, 'slot_payload' => ['h' => 'x']]); // drafted, in motion
    Content::factory()->page()->create(['site_id' => $this->site->id, 'status' => ContentStatus::Candidate, 'slot_payload' => []]); // planned / awaiting generate

    $stats = app(GrowDashboard::class)->stats($this->site);

    expect($stats['live'])->toBe(1)
        ->and($stats['building'])->toBe(1)
        ->and($stats['planned'])->toBe(1);
});

test('Grow re-run controls: re-arrange runs inline, re-ground dispatches the build', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 6,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true, 'launched' => true,
    ]);
    Queue::fake();

    Livewire::test(Grow::class)
        ->assertOk()
        ->call('reArrange')->assertOk()
        ->call('reGround')->assertOk();

    Queue::assertPushed(BuildStructure::class);
});

test('Grow per-page approve flips a review draft to approved (no WordPress contact)', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 6,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true, 'launched' => true,
    ]);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(Grow::class)
        ->assertOk()                              // the workbench renders the vocab rows + actions
        ->call('approve', $page->id)
        ->assertOk();

    expect($page->fresh()->status)->toBe(ContentStatus::Approved);
});

test('Grow regenerate queues a fresh draft on the worker for a groundable page', function () {
    growLaunched($this->site);
    Queue::fake();
    // a groundable service page (a §1 Service exists so grounding readiness passes)
    Service::factory()->create(['site_id' => $this->site->id]);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'page_type' => PageType::Service,
        'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(Grow::class)->call('regenerate', $page->id)->assertOk();

    Queue::assertPushed(GeneratePage::class);
});

test('Grow lock protects a page from a republish clobber', function () {
    growLaunched($this->site);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::Approved, 'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(Grow::class)->call('lock', $page->id)->assertOk();

    expect($page->fresh()->locked)->toBeTrue();
});

test('Grow reject sends a review draft back and captures the typed reason', function () {
    growLaunched($this->site);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(Grow::class)
        ->call('startReject', $page->id)
        ->assertSet('rejecting', $page->id)
        ->set('rejectReason', 'Tone is off-brand')
        ->call('reject', $page->id)
        ->assertSet('rejecting', null);

    $fresh = $page->fresh();
    expect($fresh->status)->toBe(ContentStatus::Rejected)
        ->and($fresh->reject_reason)->toBe('Tone is off-brand');
});

test('Grow reject falls back to a default reason when none is typed', function () {
    growLaunched($this->site);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x'],
    ]);

    Livewire::test(Grow::class)->call('startReject', $page->id)->call('reject', $page->id);

    expect($page->fresh()->reject_reason)->toBe('Rejected from the workbench');
});

test('Grow take-down force-deletes the live WP post and keeps the page republishable on the same slug', function () {
    growLaunched($this->site);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::Published,
        'slug' => 'services/drain-cleaning', 'wp_post_id' => 42, 'locally_edited' => true, 'slot_payload' => ['hero' => 'x'],
    ]);

    // The WP transport force-deletes through the plugin endpoint by control-plane ULID (§2's path).
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('deleteContent')->once()->with($page->id)->andReturn(true);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(Grow::class)->call('takeDown', $page->id)->assertOk();

    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh)->not->toBeNull()                          // STAYS in the plan (not deleted)
        ->and($fresh->wp_post_id)->toBeNull()                // WP link cleared
        ->and($fresh->status)->toBe(ContentStatus::Approved) // ready to re-publish
        ->and($fresh->slug)->toBe('services/drain-cleaning'); // same slug → no "-2"
});

test('Grow take-down of a page not on WordPress just marks it ready to publish (kept in the plan)', function () {
    growLaunched($this->site);
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id, 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x'], 'wp_post_id' => null,
    ]);

    Livewire::test(Grow::class)->call('takeDown', $page->id)->assertOk();

    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(ContentStatus::Approved)
        ->and($fresh->wp_post_id)->toBeNull();
});

test('Grow take-down only touches owned pages — a foreign page is untouched', function () {
    growLaunched($this->site);
    $foreign = Content::factory()->page()->create([
        'site_id' => Site::factory()->create()->id, 'status' => ContentStatus::Candidate, 'slot_payload' => [],
    ]);

    Livewire::test(Grow::class)->call('takeDown', $foreign->id)->assertOk();

    expect(Content::withoutGlobalScope(SiteScope::class)->find($foreign->id)->status)->toBe(ContentStatus::Candidate);
});

test('Grow surfaces the pre-publish content checklist — the gaps, what each unlocks, where to add it', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 6,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true, 'launched' => true,
    ]);
    // Bare narrative: mission/values/certifications all missing → the checklist names them.
    Livewire::test(Grow::class)
        ->assertOk()
        ->assertSee('Before you publish')
        ->assertSee('Mission statement')
        ->assertSee('Licenses & certifications')
        ->assertSee('Setup → Brand');

    // Capture everything the checklist watches → the card disappears (nothing to nag about).
    SiteNarrative::factory()->create([
        'site_id' => $this->site->id,
        'story' => 's', 'mission' => 'm',
        'values' => [['title' => 'v']], 'differentiators' => [['title' => 'd']],
        'guarantee' => ['name' => 'g'], 'certifications' => [['label' => 'c']],
        'team' => [['name' => 't']],
    ]);
    ProofItem::factory()->create([
        'site_id' => $this->site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'x'], 'is_substantiated' => true,
    ]);
    Location::factory()->create(['site_id' => $this->site->id, 'phone' => '(973) 555-0100', 'email' => 'e@x.com']);

    Livewire::test(Grow::class)->assertOk()->assertDontSee('Before you publish');
});
