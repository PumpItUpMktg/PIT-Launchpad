<?php

use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Enums\ProvenanceState;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\ConnectionsStep;
use App\Filament\Pages\Gathering\InterviewStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Filament\Pages\Gathering\VoiceStep;
use App\Filament\Pages\OwnerInterview;
use App\Gathering\IntakeExtractor;
use App\Gathering\InterviewEngine;
use App\Gathering\Provenance;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Places\PlaceCandidate;
use App\Integrations\Places\PlaceDetails;
use App\Integrations\Places\PlacesProvider;
use App\Integrations\Places\PlacesStatus;
use App\Locations\ServedTowns;
use App\Models\CoverageArea;
use App\Models\Interview;
use App\Models\Location;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SequencedClaudeClient;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

/** A scripted Places fake: resolves any line containing "Trooper" or "Montclair", fails the rest. */
function fakePlaces(): void
{
    app()->instance(PlacesProvider::class, new class implements PlacesProvider
    {
        public function search(string $query): array
        {
            foreach (['Trooper', 'Montclair'] as $known) {
                if (stripos($query, $known) !== false) {
                    return [new PlaceCandidate("place-{$known}", "SPG {$known}", "{$known}, USA")];
                }
            }

            return [];
        }

        public function details(string $placeId): ?PlaceDetails
        {
            $name = str_replace('place-', '', $placeId);

            return new PlaceDetails(
                placeId: $placeId,
                name: "SPG {$name}",
                address: "{$name}, USA",
                addressComponents: [],
                phone: '(555) 010-0000',
                hours: [],
                lat: 40.1,
                lng: -75.4,
                gbpUrl: "https://maps.google.com/?cid={$name}",
                website: null,
            );
        }

        public function smokeTest(): PlacesStatus
        {
            return new PlacesStatus(true, 'fake');
        }
    });
}

it('flag off ⇒ the new group does not register; flag on ⇒ all six steps do', function () {
    config()->set('launchpad.new_setup_enabled', false);
    expect(BusinessStep::shouldRegisterNavigation())->toBeFalse()
        ->and(InterviewStep::shouldRegisterNavigation())->toBeFalse()
        ->and(ConnectionsStep::shouldRegisterNavigation())->toBeFalse();

    config()->set('launchpad.new_setup_enabled', true);
    foreach ([BusinessStep::class, InterviewStep::class, LocationsStep::class, ServicesStep::class, VoiceStep::class, ConnectionsStep::class] as $page) {
        expect($page::shouldRegisterNavigation())->toBeTrue()
            ->and($page::getNavigationGroup())->toBe('Setup');
    }
});

it('bulk GBP import resolves lines, creates location skeletons, and keeps failures editable + non-blocking', function () {
    fakePlaces();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    $page = Livewire::test(BusinessStep::class)
        ->set('bulkInput', "SPG Trooper PA\ntotal nonsense line\nSPG Montclair NJ")
        ->call('resolveBulk');

    $results = $page->get('bulkResults');
    expect(collect($results)->where('status', 'resolved'))->toHaveCount(2)
        ->and(collect($results)->where('status', 'failed'))->toHaveCount(1);

    // Failures never block the resolved ones.
    $page->call('importResolved');
    expect(Location::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(2)
        ->and(Location::withoutGlobalScopes()->where('site_id', $site->id)->pluck('place_id')->all())
        ->toContain('place-Trooper', 'place-Montclair');

    // Idempotent by place_id; the failed line is editable and re-resolvable in place.
    $page->call('importResolved');
    expect(Location::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(2);

    $page->set('bulkResults.1.query', 'SPG Trooper duplicate check')
        ->call('retryLine', 1);
    expect($page->get('bulkResults')[1]['status'])->toBe('resolved');
});

it('a trust-facts save confirms seeded fields but leaves manual fields rowless', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $provenance = app(Provenance::class);
    // license was interview-seeded; years is typed manually.
    $site->forceFill(['license_number' => 'PA-1'])->save();
    $provenance->seed($site, 'license_number');

    Livewire::test(BusinessStep::class)
        ->set('licenseNumber', 'PA-1-EDITED')
        ->set('yearsInBusiness', '15')
        ->call('save');

    $site->refresh();
    expect($site->license_number)->toBe('PA-1-EDITED')
        ->and($site->years_in_business)->toBe(15)
        ->and($provenance->state($site, 'license_number'))->toBe(ProvenanceState::Confirmed)
        ->and($provenance->state($site, 'years_in_business'))->toBeNull(); // manual = no row
});

it('the Locations step embeds the territory workspace and saving details confirms seeded fields', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $provenance = app(Provenance::class);

    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => [
        ['name' => 'Norristown', 'state' => 'PA', 'lat' => null, 'lng' => null, 'geocoded' => false],
    ], 'coverage_suggestions' => ['towns' => [], 'phrases' => ['30 minutes from the shop']]]);
    $provenance->seed($trooper, 'served_towns');

    // The workspace's page selection works right on the step (a coverage town toggles into the pool).
    $area = CoverageArea::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'geo_id' => '4209153000', 'name' => 'Norristown', 'type' => 'county_subdivision',
        'state' => 'PA', 'source_location_ids' => [$trooper->id], 'page_selected' => false, 'source' => 'county',
    ]);

    $page = Livewire::test(LocationsStep::class)
        ->assertOk()
        ->assertSee('30 minutes from the shop')      // the interview prompt sits above the picker
        ->call('togglePageSelection', $area->geo_id);
    expect($area->fresh()->page_selected)->toBeTrue();

    // Saving the location's details confirms what the interview seeded.
    $page->set("notes.{$trooper->id}", 'High water table near the creek.')
        ->call('saveDetails', $trooper->id);
    expect($provenance->state($trooper->fresh(), 'served_towns'))->toBe(ProvenanceState::Confirmed)
        ->and($trooper->fresh()->market_notes)->toContain('water table');

    // Suggestions dismiss once handled.
    $page->call('dismissSuggestions', $trooper->id);
    expect($trooper->fresh()->coverage_suggestions)->toBeNull();
});

it('review surfaces render populated pre-provenance records cleanly (the SPG staging shape)', function () {
    // Records created long before provenance existed — no rows in the sidecar at all.
    $site = Site::factory()->create(['brand_name' => 'SPG Staging']);
    session(['guided_site_id' => $site->id]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => [
        ['name' => 'Norristown', 'state' => 'PA', 'lat' => 40.1, 'lng' => -75.3, 'geocoded' => true],
    ], 'market_notes' => 'Pre-existing notes.']);

    Livewire::test(LocationsStep::class)
        ->assertOk()
        ->assertSee('Trooper')
        ->assertSee('Norristown, PA')
        ->assertDontSee('from interview'); // no provenance rows → no chips

    Livewire::test(BusinessStep::class)->assertOk()->assertSee('SPG Staging');
    Livewire::test(VoiceStep::class)->assertOk();
    Livewire::test(ServicesStep::class)->assertOk();
    Livewire::test(ConnectionsStep::class)->assertOk();
    Livewire::test(InterviewStep::class)->assertOk()->assertSee('No interview yet');
});

it('readiness chips are state, never a wall — every step opens on an empty tenant', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    foreach ([BusinessStep::class, InterviewStep::class, LocationsStep::class, ServicesStep::class, VoiceStep::class, ConnectionsStep::class] as $pageClass) {
        Livewire::test($pageClass)->assertOk();
    }

    $locations = Livewire::test(LocationsStep::class)->instance()->readiness();
    expect($locations['state'])->toBe('empty');

    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    $locations = Livewire::test(LocationsStep::class)->instance()->readiness();
    expect($locations['state'])->toBe('attention');
});

it('the chat page drives the whole loop — begin, answer, end & extract into seeded records', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'served_towns' => []]);
    session(['guided_site_id' => $site->id]);

    // The engine + extractor are contextually bound to the real drafting lane — rebind them
    // whole (as INSTANCES, so the scripted sequence is shared across page requests) so the page
    // path is exercised without any network.
    $this->app->instance(InterviewEngine::class, new InterviewEngine(
        new SequencedClaudeClient([
            json_encode(['question' => 'Are you licensed?', 'section' => 'trust', 'coverage' => ['trust' => 'empty', 'services' => 'empty', 'coverage' => 'empty', 'market_notes' => 'empty', 'voice' => 'empty']]),
            json_encode(['question' => 'What services do you offer?', 'section' => 'services', 'coverage' => ['trust' => 'filled', 'services' => 'empty', 'coverage' => 'empty', 'market_notes' => 'empty', 'voice' => 'empty']]),
        ]),
    ));
    $this->app->instance(IntakeExtractor::class, new IntakeExtractor(
        new FakeClaudeClient(json_encode([
            'trust_facts' => ['license_number' => 'PA-777'],
            'services' => [], 'coverage' => [], 'market_notes' => [], 'voice' => [],
        ])),
        app(Provenance::class),
        app(ServedTowns::class),
    ));

    $page = Livewire::test(InterviewStep::class)
        ->call('begin')
        ->assertSee('Are you licensed?')
        ->set('input', 'Yes — PA-777, fully insured.')
        ->call('send')
        ->assertSee('What services do you offer?');

    // The meter moved on the model's self-assessment.
    expect(collect($page->instance()->meter)->firstWhere('section', InterviewSection::Trust)['state'])->toBe('filled');

    $page->call('endInterview');

    $interview = Interview::query()->where('site_id', $site->id)->first();
    expect($interview->status)->toBe(InterviewStatus::Complete)
        ->and(Site::query()->find($site->id)->license_number)->toBe('PA-777')
        ->and(app(Provenance::class)->state($site->fresh(), 'license_number'))->toBe(ProvenanceState::Seeded);
});

it('the Business step captures the trade — the structure seed the old Owner Interview used to own', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    Livewire::test(BusinessStep::class)
        ->set('trade', 'Basement Waterproofing')
        ->call('save');

    $blueprint = SiloBlueprint::withoutGlobalScopes()->where('site_id', $site->id)->first();
    expect($blueprint->trade)->toBe('Basement Waterproofing')
        ->and($blueprint->seed['trade'])->toBe('Basement Waterproofing');

    // With the seed present, the Plan step's engine-on-entry has what it needs (hasSeed passes).
    expect(trim((string) ($blueprint->seed['trade'] ?? '')))->not->toBe('');

    // The old Owner Interview is superseded: hidden once the new Setup menu is on.
    config()->set('launchpad.new_setup_enabled', true);
    expect(OwnerInterview::shouldRegisterNavigation())->toBeFalse();
    config()->set('launchpad.new_setup_enabled', false);
    expect(OwnerInterview::shouldRegisterNavigation())->toBeTrue();
});

it('the Services step suggests from the captured trade — the guided suggester, ported', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'trade' => 'basement waterproofing', 'seed' => ['trade' => 'basement waterproofing']]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair']);

    app()->instance(ClaudeClient::class, new FakeClaudeClient((string) json_encode([
        ['name' => 'French Drain Installation', 'why' => 'usually paired with sump work'],
        ['name' => 'Crawl Space Encapsulation', 'why' => 'same crews, same customers'],
    ])));

    $page = Livewire::test(ServicesStep::class)
        ->assertSee('Suggest from trade')
        ->call('suggest');
    expect($page->get('suggestions'))->toHaveCount(2);

    // Add one: it becomes a stated service and its provenance lands on the seed.
    $page->call('addSuggestion', 0);
    expect(Service::withoutGlobalScopes()->where('site_id', $site->id)->pluck('name')->all())
        ->toContain('French Drain Installation')
        ->and(SiloBlueprint::withoutGlobalScopes()->where('site_id', $site->id)->first()->seed['suggested_confirmed'])
        ->toBe(['French Drain Installation'])
        ->and($page->get('suggestions'))->toHaveCount(1);

    // Dismiss clears the rest without creating anything.
    $page->call('dismissSuggestion', 0);
    expect($page->get('suggestions'))->toBe([])
        ->and(Service::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(2);
});

it('suggesting without a trade is a guarded no-op pointing at the Business step', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    Livewire::test(ServicesStep::class)
        ->call('suggest')
        ->assertNotified()
        ->assertSet('suggestions', []);
});

it('AI fill drafts ONLY the empty enrichment fields as seeded — manual entry is never overwritten', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'trade' => 'basement waterproofing']);
    // The operator already typed a short description by hand — that field must survive untouched.
    $service = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'French Drain Installation',
        'short_description' => 'Hand-written by the owner.', 'symptoms' => null, 'scope_items' => [],
    ]);

    app()->instance(ClaudeClient::class, new FakeClaudeClient((string) json_encode([
        'short_description' => 'AI would overwrite this — it must be ignored.',
        'symptoms' => ['Water at the base of the walls', 'Musty smell after rain'],
        'scope_items' => ['Perimeter trenching', 'Drain tile install'],
        'process_steps' => ['Inspect', 'Trench', 'Install', 'Restore'],
        'cost_factors' => ['Linear footage', 'Slab access'],
    ])));

    Livewire::test(ServicesStep::class)
        ->assertSee('AI fill')
        ->call('aiEnrich', $service->id)
        ->assertNotified();

    $service->refresh();
    $provenance = app(Provenance::class);
    expect($service->short_description)->toBe('Hand-written by the owner.') // blanks only
        ->and($service->symptoms)->toBe(['Water at the base of the walls', 'Musty smell after rain'])
        ->and($service->process_steps)->toBe(['Inspect', 'Trench', 'Install', 'Restore'])
        ->and($provenance->state($service, 'symptoms'))->toBe(ProvenanceState::Seeded)
        ->and($provenance->state($service, 'short_description'))->toBeNull(); // untouched → rowless

    // Review-and-save on the Enrich form confirms the seeded fields (the step's existing
    // contract): the modal mounts pre-filled from the record; saving flips seeded → confirmed.
    Livewire::test(ServicesStep::class)
        ->callAction('enrich', ['name' => $service->name, 'short_description' => (string) $service->short_description], ['service' => $service->id])
        ->assertHasNoActionErrors();
    expect($provenance->state($service->fresh(), 'symptoms'))->toBe(ProvenanceState::Confirmed);
});

it('a failed AI fill writes nothing; a fully-enriched service is a polite no-op', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bare = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'symptoms' => null, 'short_description' => null]);

    // Garbage reply → warning, no writes, no provenance.
    app()->instance(ClaudeClient::class, new FakeClaudeClient('not json'));
    Livewire::test(ServicesStep::class)->call('aiEnrich', $bare->id)->assertNotified();
    expect($bare->fresh()->symptoms)->toBeNull()
        ->and(app(Provenance::class)->forModel($bare->fresh()))->toBe([]);

    // Everything already filled → nothing to draft.
    $full = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'Grading', 'short_description' => 'x',
        'symptoms' => ['a'], 'scope_items' => ['b'], 'process_steps' => ['c'], 'cost_factors' => ['d'],
    ]);
    Livewire::test(ServicesStep::class)->call('aiEnrich', $full->id)->assertNotified();
    expect(app(Provenance::class)->forModel($full->fresh()))->toBe([]);
});

it('the coverage meter is a RATCHET — a filled section never regresses when the model second-guesses', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'served_towns' => []]);
    session(['guided_site_id' => $site->id]);

    // Turn 1 grades trust filled + services thin; turn 2 second-guesses BOTH downward.
    $this->app->instance(InterviewEngine::class, new InterviewEngine(
        new SequencedClaudeClient([
            json_encode(['question' => 'Q1?', 'section' => 'trust', 'coverage' => ['trust' => 'filled', 'services' => 'thin', 'coverage' => 'empty', 'market_notes' => 'empty', 'voice' => 'empty']]),
            json_encode(['question' => 'Q2?', 'section' => 'services', 'coverage' => ['trust' => 'thin', 'services' => 'empty', 'coverage' => 'thin', 'market_notes' => 'empty', 'voice' => 'empty']]),
        ]),
    ));

    $page = Livewire::test(InterviewStep::class)
        ->call('begin')
        ->set('input', 'answer one')
        ->call('send');

    $meter = collect($page->instance()->meter)->keyBy(fn ($r) => $r['section']->value);
    expect($meter['trust']['state'])->toBe('filled')      // ratcheted — the downgrade is ignored
        ->and($meter['services']['state'])->toBe('thin')  // ratcheted
        ->and($meter['coverage']['state'])->toBe('thin'); // genuine progress still lands
});
