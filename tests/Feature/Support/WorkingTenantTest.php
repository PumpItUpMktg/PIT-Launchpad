<?php

use App\Models\Site;
use App\Support\WorkingTenant;

it('returns the session working site when one is selected and exists', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    expect(WorkingTenant::id())->toBe($site->id);
});

it('returns null when nothing is selected', function () {
    expect(WorkingTenant::id())->toBeNull();
});

it('returns null when the selected site no longer exists', function () {
    session(['guided_site_id' => 'a-site-that-was-deleted']);

    expect(WorkingTenant::id())->toBeNull();
});
