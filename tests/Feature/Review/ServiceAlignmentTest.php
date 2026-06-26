<?php

use App\ContentEngine\Review\ServiceAlignment;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Service;

function alignedPage(string $serviceName, array $slots): Content
{
    $page = Content::factory()->page()->create(['page_type' => PageType::Service, 'slot_payload' => $slots]);
    $service = Service::factory()->create(['site_id' => $page->site_id, 'name' => $serviceName]);
    $page->forceFill(['primary_service_id' => $service->id])->save();

    return $page->fresh();
}

it('flags a draft that never mentions its pinned service subject (the toilet bug)', function () {
    // page is about Toilet Replacement but drafted slow-drain / sewer-backup copy
    $page = alignedPage('Toilet Replacement', [
        'hero' => ['heading' => 'Fast Sewer Line Repair', 'body' => 'We clear slow drains and sewer backups across the area.'],
    ]);

    $result = (new ServiceAlignment)->check($page);

    expect($result['checked'])->toBeTrue()
        ->and($result['aligned'])->toBeFalse()
        ->and($result['service'])->toBe('Toilet Replacement')
        ->and($result['note'])->toContain('Toilet Replacement');
});

it('passes a draft that talks about its subject', function () {
    $page = alignedPage('Toilet Replacement', [
        'hero' => ['heading' => 'Toilet Replacement Done Right', 'body' => 'A cracked tank means it is time for a new toilet.'],
    ]);

    expect((new ServiceAlignment)->check($page)['aligned'])->toBeTrue();
});

it('keys on the distinctive noun, not the shared service-action word', function () {
    // "repair" is shared; a sewer page that only talks toilets must still flag (no "sewer"/"line")
    $page = alignedPage('Sewer Line Repair', [
        'hero' => ['heading' => 'Toilet Repair Specialists', 'body' => 'We fix running and clogged toilets fast.'],
    ]);

    expect((new ServiceAlignment)->check($page)['aligned'])->toBeFalse();
});

it('does not judge a page with no pinned service (hub / location / legacy)', function () {
    $page = Content::factory()->page()->create(['primary_service_id' => null, 'slot_payload' => ['hero' => 'anything']]);

    $result = (new ServiceAlignment)->check($page);

    expect($result['checked'])->toBeFalse()
        ->and($result['aligned'])->toBeTrue();
});
