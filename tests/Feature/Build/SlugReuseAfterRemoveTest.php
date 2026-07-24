<?php

use App\Build\Permalinks;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

it('a removed (soft-deleted) page frees its slug for the next build — no "-2"', function () {
    $site = Site::factory()->create();
    $old = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'slug' => 'sump-pump', 'title' => 'Sump Pump',
    ]);
    $old->delete(); // "Remove completely" soft-deletes

    $taken = app(Permalinks::class)->takenSlugs($site);

    // The removed page no longer reserves its slug, so the rebuild reuses the clean permalink.
    expect($taken)->not->toContain('sump-pump')
        ->and(app(Permalinks::class)->uniqueSlug('Sump Pump', $taken))->toBe('sump-pump');
});

it('the partial unique index lets a new LIVE page reuse a soft-deleted page\'s slug', function () {
    $site = Site::factory()->create();
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'slug' => 'commercial-pump-services', 'title' => 'Commercial Pump Services',
    ])->delete();

    // Would have thrown a unique-constraint violation under the old full index; the partial index allows it.
    $fresh = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'slug' => 'commercial-pump-services', 'title' => 'Commercial Pump Services',
    ]);

    expect($fresh->exists)->toBeTrue()
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('slug', 'commercial-pump-services')->count())->toBe(1); // one live
});
