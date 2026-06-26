<?php

use App\ContentEngine\Review\StrategyRail;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Service;
use App\Models\Silo;

it('placement names the silo and role; target reads the keyword; performance is dark pre-publish', function () {
    $page = Content::factory()->page()->create(['page_type' => PageType::Hub]);
    $silo = Silo::factory()->create(['site_id' => $page->site_id, 'name' => 'Sump Pump Installation']);
    $keyword = Keyword::factory()->create(['site_id' => $page->site_id, 'query' => 'sump pump installation', 'volume' => 880, 'difficulty' => 34]);
    $page->forceFill(['silo_id' => $silo->id, 'target_keyword_id' => $keyword->id])->save();

    $rail = (new StrategyRail)->for($page->fresh());

    expect($rail['placement']['label'])->toBe('Sump Pump Installation · pillar') // Hub → pillar role
        ->and($rail['target']['has_target'])->toBeTrue()
        ->and($rail['target']['primary'])->toBe('sump pump installation')
        ->and($rail['target']['volume'])->toBe(880)
        ->and($rail['target']['difficulty'])->toBe(34)
        // Local Falcon slot is held but dark — empty-on-purpose, not hidden.
        ->and($rail['performance']['available'])->toBeFalse()
        ->and($rail['performance']['note'])->toContain('after publish')
        ->and($rail['locked_note'])->toContain('Structure');
});

it('surfaces the pinned service subject and flags a wrong-service draft in placement', function () {
    $page = Content::factory()->page()->create([
        'page_type' => PageType::Service,
        'slot_payload' => ['hero' => ['heading' => 'Sewer backups cleared fast']], // no "toilet"
    ]);
    $service = Service::factory()->create(['site_id' => $page->site_id, 'name' => 'Toilet Replacement']);
    $page->forceFill(['primary_service_id' => $service->id])->save();

    $placement = (new StrategyRail)->for($page->fresh())['placement'];

    expect($placement['subject'])->toBe('Toilet Replacement')
        ->and($placement['mismatch'])->toBeTrue()
        ->and($placement['mismatch_note'])->toContain('Toilet Replacement');
});

it('reports no mismatch for an unpinned page (no subject to verify)', function () {
    $page = Content::factory()->page()->create(['page_type' => PageType::Hub, 'primary_service_id' => null]);

    $placement = (new StrategyRail)->for($page)['placement'];

    expect($placement['mismatch'])->toBeFalse()
        ->and($placement['subject'])->toBeNull();
});

it('degrades honestly when the page has no silo or keyword (best-effort materialize)', function () {
    $page = Content::factory()->page()->create(['page_type' => PageType::Service, 'silo_id' => null, 'target_keyword_id' => null]);

    $rail = (new StrategyRail)->for($page);

    expect($rail['placement']['silo'])->toBeNull()
        ->and($rail['placement']['label'])->toBe('service page · unassigned silo')
        ->and($rail['target']['has_target'])->toBeFalse()
        ->and($rail['target']['note'])->toBe('No keyword target set.');
});
