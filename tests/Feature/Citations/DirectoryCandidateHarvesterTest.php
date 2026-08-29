<?php

use App\Citations\DirectoryCandidateHarvester;
use App\Models\CitationFoundDomain;
use App\Models\Directory;

beforeEach(function (): void {
    $this->harvester = new DirectoryCandidateHarvester;
});

test('harvest ranks unmatched domains by breadth and excludes cataloged ones', function (): void {
    // "foundit.com" seen for two different locations; "other.io" once; "yelp.com" already in the catalog.
    CitationFoundDomain::factory()->create(['domain' => 'foundit.com', 'directory_id' => null]);
    CitationFoundDomain::factory()->create(['domain' => 'www.foundit.com', 'directory_id' => null]); // normalizes equal
    CitationFoundDomain::factory()->create(['domain' => 'other.io', 'directory_id' => null]);
    Directory::factory()->create(['domain' => 'yelp.com']);
    CitationFoundDomain::factory()->create(['domain' => 'yelp.com', 'directory_id' => null]); // stale unmatched row

    $candidates = $this->harvester->harvest();

    expect($candidates->pluck('domain')->all())->toBe(['foundit.com', 'other.io'])
        ->and($candidates->first()->occurrences)->toBe(2);
});

test('harvest honors a minimum-occurrences floor', function (): void {
    CitationFoundDomain::factory()->create(['domain' => 'foundit.com', 'directory_id' => null]);
    CitationFoundDomain::factory()->create(['domain' => 'foundit.com', 'directory_id' => null]);
    CitationFoundDomain::factory()->create(['domain' => 'rare.io', 'directory_id' => null]);

    expect($this->harvester->harvest(minOccurrences: 2)->pluck('domain')->all())->toBe(['foundit.com']);
});

test('promote adds the directory and back-fills the matching found rows', function (): void {
    $row = CitationFoundDomain::factory()->create(['domain' => 'promoteme.com', 'directory_id' => null]);

    $directory = $this->harvester->promote('https://www.promoteme.com/listing', ['name' => 'Promote Me']);

    expect($directory->domain)->toBe('promoteme.com')
        ->and($directory->name)->toBe('Promote Me')
        ->and($row->refresh()->directory_id)->toBe($directory->id);
});
