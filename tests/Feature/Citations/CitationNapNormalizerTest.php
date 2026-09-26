<?php

use App\Citations\NapNormalizer;

beforeEach(function (): void {
    $this->nap = new NapNormalizer;
});

test('phone normalization collapses formatting and country code', function (): void {
    expect($this->nap->phone('(973) 786-7834'))->toBe('9737867834')
        ->and($this->nap->phone('+1 973.786.7834'))->toBe('9737867834')
        ->and($this->nap->phone('1-973-786-7834'))->toBe('9737867834');
});

test('address normalization collapses suffix and unit formatting', function (): void {
    expect($this->nap->address('123 Main Street, Suite 4'))
        ->toBe($this->nap->address('123 Main St Ste 4'))
        ->and($this->nap->address('123 Main St #4'))
        ->toBe($this->nap->address('123 Main Street Suite 4'));
});

test('formatting-only differences are not mismatches', function (): void {
    $found = ['name' => 'ACME Plumbing, LLC', 'address' => '123 Main Street, Suite 4'];
    $canonical = ['business_name' => 'ACME Plumbing LLC', 'address_1' => '123 Main St', 'address_2' => 'Ste 4'];

    expect($this->nap->mismatches($found, $canonical))->toBe([]);
});

test('a different street number is a substantive mismatch', function (): void {
    $found = ['name' => 'ACME Plumbing', 'address' => '456 Main St'];
    $canonical = ['business_name' => 'ACME Plumbing', 'address_1' => '123 Main St'];

    $out = $this->nap->mismatches($found, $canonical);
    expect($out)->toHaveKey('address')
        ->and($out['address']['expected'])->toBe('123 Main St');
});

test('a different business name is a substantive mismatch', function (): void {
    $found = ['name' => 'ACME Plumbing & Heating', 'address' => '123 Main St'];
    $canonical = ['business_name' => 'ACME Plumbing', 'address_1' => '123 Main St'];

    expect($this->nap->mismatches($found, $canonical))->toHaveKey('name');
});

test('a directory\'s full address matches a street-only canonical, and a different ZIP is a mismatch', function (): void {
    $canonical = ['business_name' => 'ACME Plumbing', 'address_1' => '123 Main St', 'postal' => '07013'];

    expect($this->nap->mismatches(['address' => '123 Main Street, Clifton, NJ, 07013'], $canonical))->toBe([])
        ->and($this->nap->mismatches(['address' => '123 Main St, Clifton, NJ'], $canonical))->toBe([])   // no ZIP published — not a finding
        ->and($this->nap->mismatches(['address' => '123 Main St, Clifton, NJ 07011'], $canonical))->toHaveKey('address')
        ->and($this->nap->mismatches(['address' => '123 Main St'], ['business_name' => 'ACME', 'address_1' => null]))->toBe([]); // service-area: no street to compare
});

test('a different phone is a mismatch unless it is a shared number', function (): void {
    $canonical = ['business_name' => 'ACME Plumbing', 'address_1' => '123 Main St', 'phone' => '(973) 111-1111'];

    expect($this->nap->mismatches(['phone' => '973.111.1111'], $canonical))->toBe([])
        ->and($this->nap->mismatches(['phone' => '973-222-2222'], $canonical))->toHaveKey('phone')
        ->and($this->nap->mismatches(['phone' => '973-222-2222'], $canonical, ['9732222222']))->toBe([]);
});
