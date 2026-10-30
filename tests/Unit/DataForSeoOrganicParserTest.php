<?php

use App\Integrations\DataForSeo\DataForSeoClient;

test('the organic parser sees through Google\'s encrypted redirect wrapper via the breadcrumb and marks the row masked', function (): void {
    $rows = DataForSeoClient::parseOrganic([['items' => [
        ['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://sumppumpgurus.com/montclair-nj/', 'domain' => 'sumppumpgurus.com', 'title' => 'Montclair'],
        ['type' => 'organic', 'rank_absolute' => 3, 'url' => 'https://google.com/goto?url=CAESfAHr', 'domain' => 'google.com', 'title' => 'x', 'breadcrumb' => 'https://www.a-general.com › sump-pump › nj', 'website_name' => 'A-General'],
        ['type' => 'organic', 'rank_absolute' => 4, 'url' => 'https://google.com/goto?url=CAESkAEB', 'domain' => 'google.com', 'title' => 'y', 'breadcrumb' => 'townplumbing.com › services', 'website_name' => 'Town Plumbing'],
        ['type' => 'organic', 'rank_absolute' => 9, 'url' => 'https://google.com/goto?url=CAESXgHr', 'domain' => 'google.com', 'title' => 'z'],   // no breadcrumb: stays google.com, still masked
        ['type' => 'local_pack', 'rank_absolute' => 5, 'url' => 'https://google.com/goto?url=CAESYg', 'domain' => 'google.com'],
    ]]]);

    expect(array_map(fn (array $r): array => [$r['position'], $r['domain'], $r['masked']], $rows))->toBe([
        [2, 'sumppumpgurus.com', false],
        [3, 'a-general.com', true],
        [4, 'townplumbing.com', true],
        [9, 'google.com', true],
    ]);
    expect($rows[1]['website_name'])->toBe('A-General')
        ->and(DataForSeoClient::breadcrumbHost('https://www.example.com › a › b'))->toBe('example.com')
        ->and(DataForSeoClient::breadcrumbHost('example.com > a'))->toBe('example.com')
        ->and(DataForSeoClient::breadcrumbHost('Sump Pump Gurus'))->toBeNull()
        ->and(DataForSeoClient::breadcrumbHost(null))->toBeNull()
        ->and(DataForSeoClient::isMaskedUrl('https://www.google.com/url?q=x', 'google.com'))->toBeTrue()
        ->and(DataForSeoClient::isMaskedUrl('https://google.com/maps/place/x', 'google.com'))->toBeFalse();
});
