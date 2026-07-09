<?php

use App\Enums\ProofType;
use App\Guided\IntakeChecklist;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Site;
use App\Models\SiteNarrative;

function checklistKeys(Site $site): array
{
    return array_column(app(IntakeChecklist::class)->missing($site), 'key');
}

it('lists every uncaptured intake input on a bare site — the full pre-publish gap map', function () {
    $site = Site::factory()->create(['phone' => null]);

    $missing = checklistKeys($site);

    foreach (['story', 'mission', 'values', 'differentiators', 'guarantee', 'certifications', 'team', 'reviews', 'phone', 'email', 'hours'] as $key) {
        expect($missing)->toContain($key);
    }
});

it('items leave the checklist as their real §1 source is captured', function () {
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    SiteNarrative::factory()->create([
        'site_id' => $site->id,
        'story' => 'We started with one truck.',
        'mission' => 'Treat every home like our own.',
        'values' => [['title' => 'On time']],
        'differentiators' => [['title' => 'Preventive-first']],
        'guarantee' => ['name' => 'Forever Pump Warranty'],
        'certifications' => [['label' => 'NJ Master Plumber']],
        'team' => [['name' => 'Dana Rivera']],
    ]);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'Great work'], 'is_substantiated' => true,
    ]);
    Location::factory()->create(['site_id' => $site->id, 'email' => 'help@example.com']); // factory hours: mon–fri open

    expect(checklistKeys($site))->toBe([]); // everything captured → nothing to nag about
});

it('checks the REAL sources — an unsubstantiated review or all-closed hours still count as missing', function () {
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    ProofItem::factory()->create([
        'site_id' => $site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'Great work'], 'is_substantiated' => false, // not substantiated → doesn't render → still missing
    ]);
    Location::factory()->create([
        'site_id' => $site->id, 'email' => '',
        'hours' => ['mon' => 'closed', 'sun' => 'closed'], // captured but never open → the hours block can't render
    ]);

    $missing = checklistKeys($site);
    expect($missing)->toContain('reviews')->toContain('hours')->toContain('email')
        ->not->toContain('phone'); // the site phone satisfies every call button
});

it('says what each gap unlocks and where to add it', function () {
    $site = Site::factory()->create();

    $mission = collect(app(IntakeChecklist::class)->missing($site))->firstWhere('key', 'mission');

    expect($mission['unlocks'])->toContain('mission band')
        ->and($mission['where'])->toBe('Setup → Brand');
});
