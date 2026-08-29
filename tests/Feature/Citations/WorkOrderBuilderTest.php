<?php

use App\Citations\WorkOrder\WorkOrderBuilder;
use App\Enums\CitationPresence;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $this->location->id, 'business_name' => 'ACME Plumbing', 'categories' => null,
    ]);
    $this->builder = new WorkOrderBuilder;
});

function citationGap(mixed $ctx, Directory $dir, CitationPresence $presence = CitationPresence::Absent, ?array $mismatch = null): CitationStatus
{
    return CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id,
        'directory_id' => $dir->id,
        'presence' => $presence,
        'mismatch_fields' => $mismatch,
    ]);
}

test('lines are ordered must-have, then recommended, then worth-paying', function (): void {
    $recommended = Directory::factory()->create(['name' => 'Rec', 'domain_rank' => 40, 'cost_amount' => null]);
    $mustHave = Directory::factory()->create(['name' => 'Must', 'domain_rank' => 85, 'cost_amount' => null]);
    $worthPaying = Directory::factory()->create(['name' => 'Pay', 'domain_rank' => 80, 'cost_amount' => 10]);
    citationGap($this, $recommended);
    citationGap($this, $mustHave);
    citationGap($this, $worthPaying);

    $order = $this->builder->build($this->location);

    expect(collect($order->lines)->pluck('directoryName')->all())->toBe(['Must', 'Rec', 'Pay']);
});

test('low-value and skip-paid directories are excluded from the batch', function (): void {
    $lowValue = Directory::factory()->create(['domain_rank' => 15, 'cost_amount' => null]);   // free but weak
    $skipPaid = Directory::factory()->create(['domain_rank' => 80, 'cost_amount' => 300]);     // 3.75/pt
    citationGap($this, $lowValue);
    citationGap($this, $skipPaid);

    expect($this->builder->build($this->location)->lines)->toBe([]);
});

test('paid directories beyond the budget are deferred', function (): void {
    // cost 40 at value 80 → $0.50/pt (worth_paying). Budget 60 fits one, defers the second.
    $a = Directory::factory()->create(['name' => 'PayA', 'domain_rank' => 80, 'cost_amount' => 40]);
    $b = Directory::factory()->create(['name' => 'PayB', 'domain_rank' => 80, 'cost_amount' => 40]);
    citationGap($this, $a);
    citationGap($this, $b);

    $order = $this->builder->build($this->location, paidBudget: 60.0);

    expect($order->summary['paid'])->toBe(1)
        ->and($order->summary['deferred_over_budget'])->toBe(1)
        ->and($order->summary['paid_cost'])->toBe(40.0);
});

test('a mismatch gap carries the fields to correct and a correct-listing action', function (): void {
    $dir = Directory::factory()->create(['domain_rank' => 70, 'cost_amount' => null]);
    citationGap($this, $dir, CitationPresence::PresentMismatch, ['phone' => ['found' => '111', 'expected' => '222']]);

    $line = $this->builder->build($this->location)->lines[0];

    expect($line->action)->toBe(CitationPresence::PresentMismatch)
        ->and($line->actionLabel())->toBe('Correct listing')
        ->and($line->mismatchFields)->toHaveKey('phone');
});

test('the canonical NAP is captured on the work order header', function (): void {
    citationGap($this, Directory::factory()->create(['domain_rank' => 70, 'cost_amount' => null]));

    $order = $this->builder->build($this->location);

    expect($order->nap['business_name'])->toBe('ACME Plumbing');
});
