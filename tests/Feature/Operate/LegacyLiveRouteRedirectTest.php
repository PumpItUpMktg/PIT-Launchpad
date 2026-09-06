<?php

// The legacy per-family Live boards were deleted at the card consolidation (PR #769). Their old routes are
// kept ONLY as redirects to the replacement Pages boards (nav-cutover rule 10) — an old bookmark lands on
// the right board instead of 404-ing, and the retired route can't sit URL-reachable accumulating references.

it('redirects each retired Live board route to its replacement Pages board', function (string $old, string $new) {
    $this->get($old)->assertRedirect($new);
})->with([
    'core pages' => ['/admin/live-core-pages', '/admin/operate/pages/core'],
    'service pages' => ['/admin/live-services', '/admin/operate/pages/services'],
    'location pages' => ['/admin/live-locations', '/admin/operate/pages/locations'],
]);

it('leaves no live Filament page at the retired slugs (only the redirect answers)', function () {
    // A redirect is a 3xx; if a Filament page were still registered here it would 200 (or 403), not redirect.
    expect($this->get('/admin/live-core-pages')->getStatusCode())->toBeGreaterThanOrEqual(300)
        ->toBeLessThan(400);
});
