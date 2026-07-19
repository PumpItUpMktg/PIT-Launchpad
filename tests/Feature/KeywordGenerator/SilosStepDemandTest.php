<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Models\KeywordCluster;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    config()->set('launchpad.keyword_first.enabled', true);
    config()->set('launchpad.keyword_first.demand_report_volume', 500);
});

function demandCluster(Site $site, string $label, int $volume): KeywordCluster
{
    $c = new KeywordCluster;
    $c->forceFill([
        'site_id' => $site->id, 'label' => $label, 'head_term' => strtolower($label),
        'head_canonical' => strtolower($label), 'volume' => $volume, 'member_count' => 3,
        'dropped' => false, 'demand_dismissed' => false, 'serp_status' => 'confirmed',
    ])->save();

    return $c;
}

it('surfaces high-demand clusters with no service, and Add-service links one into the structure', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $encap = demandCluster($site, 'Crawl Space Encapsulation', 3360);

    $page = Livewire::test(SilosStep::class);
    expect($page->instance()->demandReport)->toHaveCount(1)
        ->and($page->instance()->demandReport[0]['label'])->toBe('Crawl Space Encapsulation');

    $page->call('createServiceFromDemand', $encap->id);

    $service = Service::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('name', 'crawl space encapsulation');
    expect($service)->not->toBeNull()
        ->and($service->structure_home_cluster_id)->toBe($encap->id)  // linked straight into its silo
        ->and($service->structure_home_flagged)->toBeFalse();

    // Now covered → the finding drops off the report.
    expect(Livewire::test(SilosStep::class)->instance()->demandReport)->toBe([]);
});

it('dismiss removes a finding from the report without dropping the demand', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $cluster = demandCluster($site, 'Sump Pump Battery Backup', 1800);

    Livewire::test(SilosStep::class)->call('dismissDemand', $cluster->id);

    expect($cluster->fresh()->demand_dismissed)->toBeTrue()
        ->and($cluster->fresh()->dropped)->toBeFalse()  // demand still in the tree
        ->and(Livewire::test(SilosStep::class)->instance()->demandReport)->toBe([]);
});

it('warns before regenerating a confirmed structure — first click arms, does not rebuild', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing'], 'confirmed_at' => now()]);

    // First Generate on a confirmed structure only arms the warning (no rebuild dispatched).
    Livewire::test(SilosStep::class)
        ->call('generate')
        ->assertSet('regenArmed', true);
});
