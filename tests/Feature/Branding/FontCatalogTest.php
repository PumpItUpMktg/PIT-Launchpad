<?php

use App\Branding\FontCatalog;

it('recognizes a real google font and returns its canonical spelling', function () {
    $catalog = new FontCatalog;

    expect($catalog->has('Inter'))->toBeTrue()
        ->and($catalog->canonical('Playfair Display'))->toBe('Playfair Display');
});

it('resolves case- and whitespace-variants to the canonical family', function () {
    $catalog = new FontCatalog;

    expect($catalog->canonical('  inter '))->toBe('Inter')
        ->and($catalog->canonical('PLAYFAIR   display'))->toBe('Playfair Display');
});

it('rejects an invented or misspelled family', function () {
    $catalog = new FontCatalog;

    expect($catalog->has('Intar'))->toBeFalse()
        ->and($catalog->has('Helvetica Neue Pro Ultra'))->toBeFalse()
        ->and($catalog->canonical('Definitely Not A Font'))->toBeNull();
});

it('reads the bundled catalog and exposes a non-trivial roster', function () {
    expect(count((new FontCatalog)->all()))->toBeGreaterThan(100);
});

it('reads from a custom catalog file when given a path', function () {
    $path = sys_get_temp_dir().'/fonts-'.uniqid().'.json';
    file_put_contents($path, json_encode(['families' => ['Custom One']]));

    $catalog = new FontCatalog($path);

    expect($catalog->has('Custom One'))->toBeTrue()
        ->and($catalog->has('Inter'))->toBeFalse();
});
