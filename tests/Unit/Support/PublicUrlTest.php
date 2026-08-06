<?php

use App\Support\PublicUrl;

it('builds a trailing-slash URL matching WordPress permalinks', function () {
    expect(PublicUrl::for('https://spg.example', 'sump-pump-maintenance/sump-pump-replacement'))
        ->toBe('https://spg.example/sump-pump-maintenance/sump-pump-replacement/');
});

it('normalizes stray slashes on the domain and slug', function () {
    expect(PublicUrl::for('https://spg.example/', '/basement-waterproofing/'))
        ->toBe('https://spg.example/basement-waterproofing/');
});

it('returns the bare domain root for the home page (empty slug)', function () {
    expect(PublicUrl::for('https://spg.example', ''))->toBe('https://spg.example/')
        ->and(PublicUrl::for('https://spg.example', null))->toBe('https://spg.example/');
});

it('returns null when the domain is missing', function () {
    expect(PublicUrl::for(null, 'anything'))->toBeNull()
        ->and(PublicUrl::for('', 'anything'))->toBeNull()
        ->and(PublicUrl::for('   ', 'anything'))->toBeNull();
});
