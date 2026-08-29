<?php

use App\Citations\WorkOrder\WorkOrderBuilder;
use App\Citations\WorkOrder\WorkOrderCsv;
use App\Citations\WorkOrder\WorkOrderPdf;
use App\Enums\CitationState;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $this->location->id, 'business_name' => 'ACME Plumbing', 'phone_primary' => '973-111-1111', 'categories' => null,
    ]);
    $dir = Directory::factory()->create(['name' => 'Yelp', 'domain' => 'yelp.com', 'domain_rank' => 80, 'cost_amount' => null]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $this->location->id, 'directory_id' => $dir->id, 'state' => CitationState::NotListed,
    ]);
    $this->order = (new WorkOrderBuilder)->build($this->location);
});

test('the CSV carries a header and a self-contained row with the NAP', function (): void {
    $csv = (new WorkOrderCsv)->render($this->order);

    expect($csv)->toContain('action,directory,domain')
        ->and($csv)->toContain('Create listing')
        ->and($csv)->toContain('yelp.com')
        ->and($csv)->toContain('ACME Plumbing')
        ->and($csv)->toContain('973-111-1111');
});

test('the PDF renders as a real PDF document', function (): void {
    $bytes = (new WorkOrderPdf)->render($this->order)->output();

    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

test('the command writes both files to storage', function (): void {
    Storage::fake('local');

    $this->artisan('launchpad:citation-work-order', ['--location' => $this->location->id])->assertSuccessful();

    $files = Storage::disk('local')->files('citation-work-orders');
    expect(collect($files)->filter(fn (string $f): bool => str_ends_with($f, '.pdf')))->toHaveCount(1)
        ->and(collect($files)->filter(fn (string $f): bool => str_ends_with($f, '.csv')))->toHaveCount(1);
});
