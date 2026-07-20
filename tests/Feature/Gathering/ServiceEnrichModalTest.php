<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The enrich modal must render for ANY stored service-data shape. A legacy/foreign shape (null, a
 * bare string, or a nested [{item: …}] list where a simple() repeater wants a flat string list) once
 * threw "Error while loading page" on the Services step — the inner TextInput got an array and hit an
 * "Array to string conversion" on render. fillForm now normalizes into the schema's shape.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

function enrichSite(): Site
{
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    return $site;
}

/** The simple() repeater hydrates to [uuid => ['item' => value]]; pull the flat values back out. */
function repeaterValues(array $state): array
{
    return collect($state)->map(fn ($row) => $row['item'] ?? null)->values()->all();
}

function mountEnrichData(Service $service): array
{
    return Livewire::test(ServicesStep::class)
        ->mountAction('enrich', ['service' => $service->id])
        ->assertActionMounted('enrich')       // the modal opened — no "Error while loading page"
        ->assertHasNoActionErrors()
        ->instance()->mountedActions[0]['data'];
}

it('opens for a not-yet-enriched service (all fields null)', function () {
    $svc = Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => enrichSite()->id, 'name' => 'Fresh']);

    $data = mountEnrichData($svc);
    expect($data['symptoms'])->toBe([])->and($data['name'])->toBe('Fresh');
});

it('opens for a legacy nested [{item: …}] / bare-string / wrong-type shape by normalizing it', function () {
    $svc = Service::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => enrichSite()->id, 'name' => 'Legacy',
        'symptoms' => [['item' => 'musty smell'], ['item' => 'wet floor']], // nested → must flatten
        'scope_items' => 'inspection',                                        // bare string → one-item list
        'price_range' => 'not-an-array',                                      // wrong type → array
        'comparison' => 'nope',                                               // wrong type → array
    ]);

    $data = mountEnrichData($svc);
    expect(repeaterValues($data['symptoms']))->toBe(['musty smell', 'wet floor'])
        ->and(repeaterValues($data['scope_items']))->toBe(['inspection'])
        // every repeater value is a plain string — never an array (the old crash).
        ->and(collect($data['symptoms'])->every(fn ($r) => is_string($r['item'])))->toBeTrue();
});

it('opens for a well-formed enriched service and keeps its data', function () {
    $svc = Service::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => enrichSite()->id, 'name' => 'Sump Pump Repair',
        'symptoms' => ['loud pump', 'water backup'],
        'price_range' => ['low' => 200, 'high' => 600, 'unit' => 'per repair'],
        'comparison' => ['enabled' => true, 'option_a' => ['name' => 'A', 'points' => [['item' => 'fast']]]],
    ]);

    $data = mountEnrichData($svc);
    expect(repeaterValues($data['symptoms']))->toBe(['loud pump', 'water backup'])
        ->and((int) $data['price_range']['high'])->toBe(600)
        ->and(repeaterValues($data['comparison']['option_a']['points']))->toBe(['fast']); // nested points flattened
});
