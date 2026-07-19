<?php

use App\Enums\SpokePageType;
use App\Enums\SpokeTag;
use App\Integrations\Embedding\EmbeddingProvider;
use App\KeywordGenerator\Cluster\CorpusEmbeddings;
use App\KeywordGenerator\Cluster\HeadTermSelector;
use App\KeywordGenerator\Derive\DemandWithoutServiceReport;
use App\KeywordGenerator\Derive\ServiceStructureMapper;
use App\KeywordGenerator\Derive\StructureDeriver;
use App\KeywordGenerator\Derive\ViabilityMerger;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Service;
use App\Models\Site;
use App\Models\Spoke;
use App\SiloCreator\ViabilityGuard;

class DeriveEmb implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'drain') => [1.0, 0.0, 0.0],
            str_contains($t, 'sump') => [0.0, 1.0, 0.0],
            str_contains($t, 'crawl') || str_contains($t, 'encapsulat') => [0.0, 0.0, 1.0],
            default => [0.0, 0.0, 0.0, 1.0], // orthogonal to every family → a true non-match
        };
    }
}

/** @param list<array{0:string,1:int,2:string}> $members */
function kfCluster(Site $site, string $label, array $members): KeywordCluster
{
    $cluster = new KeywordCluster;
    $cluster->forceFill([
        'site_id' => $site->id, 'label' => $label, 'head_term' => $members[0][0], 'head_canonical' => mb_strtolower($members[0][0]),
        'volume' => $members[0][1], 'member_count' => count($members), 'dropped' => false, 'serp_status' => 'confirmed',
    ])->save();
    foreach ($members as [$term, $volume, $intent]) {
        (new KeywordCorpus)->forceFill([
            'site_id' => $site->id, 'term' => $term, 'canonical' => mb_strtolower($term),
            'volume' => $volume, 'intent' => $intent, 'source' => 'expansion', 'cluster_id' => $cluster->id,
        ])->save();
    }

    return $cluster;
}

function kfMerger(): ViabilityMerger
{
    return new ViabilityMerger(new CorpusEmbeddings(new DeriveEmb), new ViabilityGuard);
}

it('merges thin clusters into their nearest neighbor — zero thin silos at output', function () {
    $site = Site::factory()->create();
    kfCluster($site, 'Drainage', [['french drain installation', 5180, 'transactional'], ['french drain cost', 2400, 'commercial'], ['french drain repair', 1200, 'transactional']]);
    kfCluster($site, 'Curtain', [['curtain drain', 900, 'commercial']]); // thin (1 < 3), same family
    kfCluster($site, 'Sump', [['sump pump repair', 2720, 'transactional'], ['sump pump install', 1900, 'transactional'], ['sump pump cost', 1100, 'commercial']]);

    $survivors = kfMerger()->merge($site);

    // Thin Curtain folded into the nearest (Drainage); two viable silos remain, none thin.
    expect($survivors)->toHaveCount(2);
    $labels = array_map(fn ($c) => $c->label, $survivors);
    expect($labels)->toContain('Drainage')->toContain('Sump')->not->toContain('Curtain');

    expect(KeywordCluster::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(2);
    $curtainTerm = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('canonical', 'curtain drain');
    $drainage = collect($survivors)->firstWhere('label', 'Drainage');
    expect($curtainTerm->cluster_id)->toBe($drainage->id) // repointed to survivor
        ->and($drainage->fresh()->member_count)->toBe(4);
});

it('derives blueprint Spoke rows headed by the demand term, routing intent by tag', function () {
    $site = Site::factory()->create();
    $cluster = kfCluster($site, 'Drainage', [
        ['french drain installation', 5180, 'transactional'],
        ['french drain cost', 2400, 'commercial'],
        ['how to install a french drain', 800, 'informational'],
    ]);

    (new StructureDeriver(new HeadTermSelector(new IntentClassifier)))->derive($site, [$cluster]);

    $spokes = Spoke::withoutGlobalScopes()->where('site_id', $site->id)->get();
    $pillar = $spokes->firstWhere('is_pillar', true);
    expect($pillar->name)->toBe('french drain installation')  // hub = highest-demand term
        ->and($pillar->silo)->toBe('Drainage')
        ->and($pillar->tag)->toBe(SpokeTag::Core);

    // The informational member is non-core Content → Prune routes it to the blog queue.
    $info = $spokes->firstWhere('name', 'how to install a french drain');
    expect($info->tag)->toBe(SpokeTag::Adjacent)
        ->and($info->page_type)->toBe(SpokePageType::Content);
    // The commercial member is Core → own page / fold by volume.
    $cost = $spokes->firstWhere('name', 'french drain cost');
    expect($cost->tag)->toBe(SpokeTag::Core)
        ->and($cost->page_type)->toBe(SpokePageType::Service);
});

it('maps services onto the derived clusters, flagging non-matches', function () {
    $site = Site::factory()->create();
    $drainage = kfCluster($site, 'Drainage', [['french drain installation', 5180, 'transactional'], ['french drain cost', 2400, 'commercial'], ['french drain repair', 1200, 'transactional']]);
    $matched = Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain Cleaning']);
    $orphan = Service::factory()->create(['site_id' => $site->id, 'name' => 'Gutter Widget']);

    $result = (new ServiceStructureMapper(new DeriveEmb))->map($site, [$drainage]);

    expect($result)->toBe(['mapped' => 2, 'flagged' => 1]);
    expect($matched->fresh()->structure_home_cluster_id)->toBe($drainage->id)
        ->and($matched->fresh()->structure_home_flagged)->toBeFalse();
    expect($orphan->fresh()->structure_home_flagged)->toBeTrue(); // no real cluster match
});

it('reports high-demand clusters with no matching service (the BD output)', function () {
    $site = Site::factory()->create();
    $drainage = kfCluster($site, 'Drainage', [['french drain installation', 5180, 'transactional'], ['french drain cost', 2400, 'commercial'], ['french drain repair', 1200, 'transactional']]);
    $encap = kfCluster($site, 'Crawl Space Encapsulation', [['crawl space encapsulation', 3360, 'commercial'], ['crawl space vapor barrier', 900, 'commercial'], ['crawl space repair', 700, 'commercial']]);
    // A service covers drainage; nothing covers encapsulation.
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drains', 'structure_home_cluster_id' => $drainage->id, 'structure_home_flagged' => false]);

    $findings = (new DemandWithoutServiceReport)->for($site);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['label'])->toBe('Crawl Space Encapsulation')
        ->and($findings[0]['volume'])->toBe(3360)
        ->and($findings[0]['cluster_id'])->toBe($encap->id);
});

it('the derive-structure command runs the pipeline and reports the counts', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    kfCluster($site, 'Drainage', [['french drain installation', 5180, 'transactional'], ['french drain cost', 2400, 'commercial'], ['french drain repair', 1200, 'transactional']]);
    kfCluster($site, 'Encapsulation', [['crawl space encapsulation', 3360, 'commercial'], ['crawl space vapor barrier', 900, 'commercial'], ['crawl space repair', 700, 'commercial']]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain Installation']);

    $this->app->instance(EmbeddingProvider::class, new DeriveEmb);

    $this->artisan('launchpad:derive-structure', ['--site' => $site->id])
        ->expectsOutputToContain('Derived SPG: 2 silos (zero thin)')
        ->expectsOutputToContain('Demand without service:')
        ->assertSuccessful();

    // Structure landed as blueprint spokes; the derivation is idempotent-replaceable.
    expect(Spoke::withoutGlobalScopes()->where('site_id', $site->id)->where('is_pillar', true)->count())->toBe(2);
});
