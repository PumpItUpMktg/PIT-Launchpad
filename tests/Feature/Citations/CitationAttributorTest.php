<?php

use App\Citations\CitationAttributor;

beforeEach(function (): void {
    $this->attr = new CitationAttributor;
});

$siblings = [
    ['location_id' => 'loc-a', 'phone_primary' => '973-111-1111', 'address_1' => '123 Main St', 'city' => 'Clifton', 'postal' => '07011'],
    ['location_id' => 'loc-b', 'phone_primary' => '201-222-2222', 'address_1' => '999 Oak Ave', 'city' => 'Paramus', 'postal' => '07652'],
];

test('a local phone number attributes decisively to its owner', function () use ($siblings): void {
    $result = $this->attr->attribute(
        ['name' => 'ACME', 'address' => '123 Main St, Clifton NJ', 'phone' => '(973) 111-1111'],
        $siblings,
    );

    expect($result->locationId)->toBe('loc-a')
        ->and($result->ambiguous)->toBeFalse()
        ->and($result->confidence)->toBeGreaterThanOrEqual(60);
});

test('a bare organic result with no distinguishing NAP across siblings is ambiguous', function () use ($siblings): void {
    $result = $this->attr->attribute(['name' => 'ACME', 'address' => null, 'phone' => null], $siblings);

    expect($result->ambiguous)->toBeTrue()
        ->and($result->locationId)->toBeNull();
});

test('an un-owned shared number contributes zero signal', function () use ($siblings): void {
    // The shared number is not any location's primary and has no owner → it must not tip attribution.
    $result = $this->attr->attribute(
        ['name' => 'ACME', 'address' => null, 'phone' => '800-555-0000'],
        $siblings,
        ['8005550000' => null],
    );

    expect($result->ambiguous)->toBeTrue();
});

test('a shared number owned by a location attributes only when the address corroborates', function () use ($siblings): void {
    $result = $this->attr->attribute(
        ['name' => 'ACME', 'address' => '123 Main Street, Clifton', 'phone' => '800-555-0000'],
        $siblings,
        ['8005550000' => 'loc-a'],
    );

    expect($result->locationId)->toBe('loc-a')
        ->and($result->ambiguous)->toBeFalse();
});

test('a tie between two siblings is parked as ambiguous', function (): void {
    $twins = [
        ['location_id' => 'loc-a', 'phone_primary' => '973-111-1111', 'address_1' => '10 Same St', 'city' => 'Clifton', 'postal' => '07011'],
        ['location_id' => 'loc-b', 'phone_primary' => '201-222-2222', 'address_1' => '10 Same St', 'city' => 'Clifton', 'postal' => '07011'],
    ];

    $result = $this->attr->attribute(['name' => 'ACME', 'address' => '10 Same St', 'phone' => null], $twins);

    expect($result->ambiguous)->toBeTrue();
});

test('a page the scanner cannot read is attributed by the town its URL or title names — one town only', function () use ($siblings): void {
    // Clifton in the slug → Clifton's, at medium confidence (above the floor, below a phone match).
    $bySlug = $this->attr->attribute(['url' => 'https://www.yelp.com/biz/acme-plumbing-clifton', 'title' => 'ACME Plumbing'], $siblings);
    expect($bySlug->locationId)->toBe('loc-a')->and($bySlug->ambiguous)->toBeFalse()->and($bySlug->confidence)->toBe(35);

    // Paramus in the SERP title → Paramus's.
    $byTitle = $this->attr->attribute(['url' => 'https://nextdoor.com/pages/acme-plumbing-1', 'title' => 'ACME Plumbing - Paramus, NJ'], $siblings);
    expect($byTitle->locationId)->toBe('loc-b')->and($byTitle->ambiguous)->toBeFalse();

    // Both towns named → no owner from the URL → still ambiguous, never guessed.
    $both = $this->attr->attribute(['url' => 'https://dir.test/acme-plumbing-clifton-paramus', 'title' => ''], $siblings);
    expect($both->ambiguous)->toBeTrue();

    // A scraped phone still outranks the slug: the page says Clifton but the number is Paramus's.
    $phoneWins = $this->attr->attribute(['url' => 'https://dir.test/acme-plumbing-clifton', 'phone' => '201-222-2222'], $siblings);
    expect($phoneWins->locationId)->toBe('loc-b');
});
