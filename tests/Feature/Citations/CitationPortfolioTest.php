<?php

use App\Citations\Ui\CitationPortfolio;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

function portfolioTenant(string $brand, CitationPresence $presence, CitationLifecycleState $lifecycle = CitationLifecycleState::None): Site
{
    $site = Site::factory()->create(['brand_name' => $brand]);
    CurrentSite::set($site->id);
    $location = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=1']);
    LocationNapProfile::factory()->create(['site_id' => $site->id, 'location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);
    CitationStatus::factory()->create([
        'site_id' => $site->id, 'location_id' => $location->id, 'directory_id' => $dir->id,
        'presence' => $presence, 'lifecycle' => $lifecycle,
    ]);

    return $site;
}

test('the portfolio sorts most-urgent-first (stalled desc, then mismatch desc)', function (): void {
    portfolioTenant('Beta clean', CitationPresence::PresentMatch);
    portfolioTenant('Alpha stalled', CitationPresence::Absent, CitationLifecycleState::Stalled);
    portfolioTenant('Gamma mismatch', CitationPresence::PresentMismatch);

    $rows = (new CitationPortfolio)->rows();

    expect(collect($rows)->pluck('tenantName')->all())->toBe(['Alpha stalled', 'Gamma mismatch', 'Beta clean']);
});

test('a tenant row carries listing count, median coverage, and the exception counts', function (): void {
    portfolioTenant('Solo', CitationPresence::PresentMatch);

    $row = (new CitationPortfolio)->rows()[0];

    expect($row->tenantName)->toBe('Solo')
        ->and($row->listingCount)->toBe(1)
        ->and($row->medianCoverage)->toBe(100)   // the one listing is fully covered
        ->and($row->stalledCount)->toBe(0)
        ->and($row->mismatchCount)->toBe(0);
});
