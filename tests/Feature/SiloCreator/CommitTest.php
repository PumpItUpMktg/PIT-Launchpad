<?php

use App\Enums\SiloType;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\SiloCreator\AutoProposer;
use App\SiloCreator\GeoNeutralViolationException;
use App\SiloCreator\RuleSet;
use App\SiloCreator\SiloCommitter;
use App\SiloCreator\SiloProposal;
use App\SiloCreator\SiloProposalSet;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SiloCreatorFixtures;

test('commit persists a coherent silo tree with hierarchy, mapping and pillars', function () {
    ['site' => $site, 'plumbing' => $plumbing] = SiloCreatorFixtures::catalog();
    $this->app->instance(ClaudeClient::class, new FakeClaudeClient(SiloCreatorFixtures::themesJson()));

    // Nest the topical silo under the Plumbing pillar for a hierarchy assertion.
    $set = app(AutoProposer::class)->propose($site)->map(
        fn (SiloProposal $p) => $p->type === SiloType::Topical ? $p->withParent('Plumbing') : $p
    );

    $silos = app(SiloCommitter::class)->commit($site, $set);

    $persisted = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

    // Two pillars + one viable topical silo.
    expect($persisted)->toHaveCount(3);

    $plumbingSilo = $persisted->firstWhere('name', 'Plumbing');
    expect($plumbingSilo->type)->toBe(SiloType::ServicePillar)
        ->and($plumbingSilo->services->pluck('id'))->toContain($plumbing->id)
        ->and($plumbingSilo->rule_set)->toHaveKey('seed_terms')
        ->and($plumbingSilo->wp_category_id)->toBeNull()           // left for §2
        ->and($plumbingSilo->pillar_content_id)->not->toBeNull();  // pillar stub linked

    $topical = $persisted->firstWhere('type', SiloType::Topical);
    expect($topical->parent_silo_id)->toBe($plumbingSilo->id);

    // Pillar Content stubs exist for every silo.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(3);
});

test('commit rejects a silo that contains a geo term', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    $set = new SiloProposalSet([
        new SiloProposal(
            type: SiloType::ServicePillar,
            name: 'Plumbing in Austin',
            ruleSet: new RuleSet(seedTerms: ['plumbing']),
        ),
    ]);

    expect(fn () => app(SiloCommitter::class)->commit($site, $set))
        ->toThrow(GeoNeutralViolationException::class);

    expect(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
