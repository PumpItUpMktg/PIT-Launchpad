<?php

use App\Enums\ProvenanceState;
use App\Gathering\IntakeExtractor;
use App\Gathering\Provenance;
use App\Locations\ServedTowns;
use App\Models\Interview;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use App\Models\VoiceProfile;
use Tests\Support\FakeClaudeClient;

function extractionJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'trust_facts' => ['license_number' => 'PA-12345', 'insured' => true, 'years_in_business' => 12],
        'services' => [[
            'name' => 'French Drain Installation',
            'short_description' => 'Interior drains that keep basements dry.',
            'symptoms' => ['Water at the foundation after rain'],
            'cost_factors' => ['Linear footage', 'Slab access'],
            'price_range' => ['low' => 3000, 'high' => 9000, 'unit' => 'per system'],
        ]],
        'coverage' => [[
            'location' => 'Trooper office',
            'towns' => ['Norristown, PA', 'Phoenixville, PA'],
            'unresolved' => ['30 minutes from the shop'],
        ]],
        'market_notes' => [['location' => 'Trooper office', 'notes' => 'Older housing stock, stone foundations, high water table near the creek.']],
        'voice' => ['persona' => 'Straight-talking second-generation owner', 'language_rules' => ['Never say "cheap"'], 'cta_voice' => 'direct'],
    ], $overrides));
}

function extractor(string $response): IntakeExtractor
{
    return new IntakeExtractor(new FakeClaudeClient($response), app(Provenance::class), app(ServedTowns::class));
}

function gatherSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $location = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'served_towns' => []]);
    $interview = Interview::factory()->create(['site_id' => $site->id]);
    $interview->turns()->create(['role' => 'assistant', 'content' => 'Tell me everything.']);
    $interview->turns()->create(['role' => 'operator', 'content' => 'Everything said.']);

    return [$site, $location, $interview];
}

it('seeds trust facts, services, towns, market notes, and a voice DRAFT — all marked seeded', function () {
    [$site, $location, $interview] = gatherSite();

    $summary = extractor(extractionJson())->extract($interview);

    $site->refresh();
    $location->refresh();
    $provenance = app(Provenance::class);

    expect($site->license_number)->toBe('PA-12345')
        ->and($site->insured)->toBeTrue()
        ->and($site->years_in_business)->toBe(12)
        ->and($provenance->state($site, 'license_number'))->toBe(ProvenanceState::Seeded);

    $service = Service::withoutGlobalScopes()->where('site_id', $site->id)->where('name', 'French Drain Installation')->first();
    expect($service)->not->toBeNull()
        ->and($service->symptoms)->toBe(['Water at the foundation after rain'])
        ->and($service->price_range['high'])->toBe(9000)
        ->and($provenance->state($service, 'symptoms'))->toBe(ProvenanceState::Seeded);

    expect(collect($location->served_towns)->pluck('name')->all())->toBe(['Norristown', 'Phoenixville'])
        ->and($location->market_notes)->toContain('stone foundations')
        ->and($provenance->state($location, 'served_towns'))->toBe(ProvenanceState::Seeded)
        // The fuzzy phrase stays an operator prompt, never a saved row.
        ->and($location->coverage_suggestions['phrases'])->toBe(['30 minutes from the shop']);

    $draft = VoiceProfile::withoutGlobalScopes()->where('site_id', $site->id)->first();
    expect($draft->status->value)->toBe('draft') // a draft, never auto-activated
        ->and($draft->language_rules)->toBe(['Never say "cheap"'])
        ->and($summary['voice'])->toBeTrue();
});

it('never overwrites a confirmed field — re-extraction updates only seeded/empty ones', function () {
    [$site, $location, $interview] = gatherSite();
    $provenance = app(Provenance::class);

    extractor(extractionJson())->extract($interview);

    // Operator confirms the license on the review surface; market notes stay merely seeded.
    $site->refresh()->forceFill(['license_number' => 'PA-99999-CONFIRMED'])->save();
    $provenance->confirm($site->fresh(), 'license_number');

    // Re-run with different values everywhere.
    extractor(extractionJson([
        'trust_facts' => ['license_number' => 'PA-OVERWRITE', 'years_in_business' => 20],
        'market_notes' => [['location' => 'Trooper office', 'notes' => 'Updated notes from the second pass.']],
    ]))->extract($interview);

    $site->refresh();
    $location->refresh();
    expect($site->license_number)->toBe('PA-99999-CONFIRMED')  // confirmed → untouched
        ->and($site->years_in_business)->toBe(20)               // seeded → updated
        ->and($location->market_notes)->toContain('Updated notes'); // seeded → updated
});

it('respects one-town-one-location: a town served elsewhere lands as a suggestion, not a row', function () {
    [$site, $location, $interview] = gatherSite();
    // Another location already owns Norristown.
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair office',
        'served_towns' => [['name' => 'Norristown', 'state' => 'PA', 'lat' => null, 'lng' => null, 'geocoded' => false]],
    ]);

    extractor(extractionJson())->extract($interview);

    $location->refresh();
    expect(collect($location->served_towns)->pluck('name')->all())->toBe(['Phoenixville']) // conflict excluded
        ->and(collect($location->coverage_suggestions['towns'])->join(','))->toContain('Norristown'); // surfaced for the operator
});

it('the full skip path needs no interview at all — extraction is opt-in, surfaces are directly editable', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Solo office']);

    // No Interview row exists; nothing was seeded; provenance stays empty (manual entry is normal data).
    $location = Location::withoutGlobalScopes()->where('site_id', $site->id)->first();
    $location->forceFill(['market_notes' => 'Typed by hand.'])->save();

    expect(app(Provenance::class)->forModel($location))->toBe([])
        ->and(Interview::query()->where('site_id', $site->id)->exists())->toBeFalse();
});
