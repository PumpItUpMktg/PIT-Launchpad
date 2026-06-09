<?php

use App\ContentEngine\RelevanceScorer;
use App\Enums\RelevanceBand;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\News;
use Tests\Support\ScriptedClaudeClient;

it('drops an unroutable item without a hint but routes it to the feed hint as a backstop', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Plumbing',
        'rule_set' => ['include_patterns' => ['plumbing'], 'exclude_patterns' => []],
    ]);
    $silos = Silo::where('site_id', $site->id)->get();

    // The model finds the item relevant + brand-safe but matches no silo.
    $claude = (new ScriptedClaudeClient)->on('Pipe maintenance', json_encode([
        'relevance' => 0.8,
        'matched_silo' => null,
        'angle' => 'A homeowner takeaway',
        'advisory_value' => 0.7,
        'timeliness' => 0.6,
        'local_relevance' => false,
        'brand_safe' => true,
    ]));
    $scorer = new RelevanceScorer($claude);
    $item = News::item('Pipe maintenance for cold snaps');

    // No hint → the silo-match gate drops it.
    expect($scorer->score($item, $silos)->band)->toBe(RelevanceBand::Dropped);

    // The feed's silo hint rescues + routes it (score still gates the band).
    $hinted = $scorer->score($item, $silos, $silo->id);
    expect($hinted->band)->toBe(RelevanceBand::DraftReady)
        ->and($hinted->matchedSiloId)->toBe($silo->id);
});

it('keeps content routing authoritative — a model match wins over the hint', function () {
    $site = Site::factory()->create();
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'rule_set' => ['include_patterns' => ['plumbing']]]);
    $water = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Water Heaters', 'rule_set' => ['include_patterns' => ['water heater']]]);
    $plumbing = Silo::where('site_id', $site->id)->where('name', 'Plumbing')->first();
    $silos = Silo::where('site_id', $site->id)->get();

    $claude = (new ScriptedClaudeClient)->on('Tankless', json_encode([
        'relevance' => 0.9, 'matched_silo' => 'Water Heaters', 'angle' => 'x',
        'advisory_value' => 0.8, 'timeliness' => 0.7, 'local_relevance' => false, 'brand_safe' => true,
    ]));

    // Hint points at Plumbing, but the model matched Water Heaters — the model wins.
    $result = (new RelevanceScorer($claude))->score(News::item('Tankless upgrade guide'), $silos, $plumbing->id);

    expect($result->matchedSiloId)->toBe($water->id);
});
