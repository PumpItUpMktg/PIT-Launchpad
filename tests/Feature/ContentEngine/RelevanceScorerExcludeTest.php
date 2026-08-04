<?php

use App\ContentEngine\RelevanceScorer;
use App\Enums\RelevanceBand;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;
use Tests\Support\News;
use Tests\Support\ScriptedClaudeClient;

function siloWith(Site $site, string $name, array $include, array $exclude = []): Silo
{
    return Silo::factory()->create([
        'site_id' => $site->id,
        'name' => $name,
        'rule_set' => ['include_patterns' => $include, 'exclude_patterns' => $exclude],
    ]);
}

it('vetoes a routing that hits the matched silo exclude patterns (residential post → Commercial silo)', function () {
    $site = Site::factory()->create();
    $commercial = siloWith($site, 'Commercial Pump Services', ['pump', 'drainage'], ['residential', 'homeowner']);

    // The model (mis)routes a residential yard-drainage story to the Commercial silo…
    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.8, 'matched_silo' => 'Commercial Pump Services', 'brand_safe' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(
        News::item('Fixing residential yard drainage before winter', summary: 'A homeowner guide to soggy lawns.'),
        new Collection([$commercial]),
    );

    // …the exclude guard rejects it rather than mis-filing under Commercial.
    expect($result->matchedSiloId)->toBeNull()
        ->and($result->band)->toBe(RelevanceBand::Dropped);
});

it('keeps a routing when the item does not hit the exclude patterns', function () {
    $site = Site::factory()->create();
    $commercial = siloWith($site, 'Commercial Pump Services', ['pump', 'drainage'], ['residential', 'homeowner']);

    $claude = (new ScriptedClaudeClient)->fallback(json_encode([
        'relevance' => 0.8, 'matched_silo' => 'Commercial Pump Services', 'brand_safe' => true,
    ]));

    $result = (new RelevanceScorer($claude))->score(
        News::item('Commercial sump pump maintenance for warehouses', summary: 'Facility drainage upkeep.'),
        new Collection([$commercial]),
    );

    expect($result->matchedSiloId)->toBe($commercial->id)
        ->and($result->band)->toBe(RelevanceBand::DraftReady);
});
