<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SiloType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;

function fresh(string $id): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->findOrFail($id);
}

/** A §4 pillar that was flipped to kind=post and published through the blog template. */
function flippedPillar(Site $site, SiloType $type = SiloType::ServicePillar): array
{
    $silo = Silo::factory()->create(['site_id' => $site->id, 'type' => $type]);
    $pillar = Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'wp_post_id' => 17, 'published_at' => now(), 'body' => 'flipped blog body',
    ]);
    $silo->forceFill(['pillar_content_id' => $pillar->id])->save();

    return [$silo, $pillar];
}

it('repairs a flipped pillar by content id (→ page/service/candidate, kit re-pinned, artifacts cleared)', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();
    [, $pillar] = flippedPillar($site);

    $this->artisan('launchpad:repair-page-pillar', ['content' => $pillar->id])->assertSuccessful();

    $serviceKit = WireframeKit::query()->where('page_type', PageType::Service->value)->whereNull('site_id')->firstOrFail();
    expect(fresh($pillar->id))
        ->kind->toBe(ContentKind::Page)
        ->page_type->toBe(PageType::Service)
        ->status->toBe(ContentStatus::Candidate)
        ->wireframe_kit_id->toBe($serviceKit->id)
        ->wp_post_id->toBeNull()
        ->body->toBeNull()
        ->published_at->toBeNull();
});

it('sweeps every flipped pillar for a --site, leaving correct pages and non-pillars untouched', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();
    [, $p1] = flippedPillar($site);
    [, $p2] = flippedPillar($site, SiloType::Topical);

    // A legitimately published page pillar — must NOT be reset.
    $okSilo = Silo::factory()->create(['site_id' => $site->id, 'type' => SiloType::ServicePillar]);
    $okPillar = Content::factory()->page()->create([
        'site_id' => $site->id, 'silo_id' => $okSilo->id,
        'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 99,
    ]);
    $okSilo->forceFill(['pillar_content_id' => $okPillar->id])->save();

    // A non-pillar news post — no silo points to it.
    $newsPost = Content::factory()->post()->create(['site_id' => $site->id, 'kind' => ContentKind::Post]);

    $this->artisan('launchpad:repair-page-pillar', ['--site' => $site->id])->assertSuccessful();

    expect(fresh($p1->id)->kind)->toBe(ContentKind::Page)
        ->and(fresh($p2->id)->kind)->toBe(ContentKind::Page)
        ->and(fresh($p2->id)->page_type)->toBe(PageType::Pillar)          // topical → pillar
        ->and(fresh($okPillar->id)->kind)->toBe(ContentKind::Page)        // untouched
        ->and(fresh($okPillar->id)->status)->toBe(ContentStatus::Published) // NOT reset
        ->and(fresh($newsPost->id)->kind)->toBe(ContentKind::Post);       // untouched
});

it('is idempotent — a second run repairs nothing', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();
    [, $pillar] = flippedPillar($site);

    $this->artisan('launchpad:repair-page-pillar', ['content' => $pillar->id])->assertSuccessful();
    $this->artisan('launchpad:repair-page-pillar', ['content' => $pillar->id])->assertSuccessful();

    expect(fresh($pillar->id)->kind)->toBe(ContentKind::Page)
        ->and(fresh($pillar->id)->status)->toBe(ContentStatus::Candidate);
});

it('fails when the content id is not a silo pillar', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id]);

    $this->artisan('launchpad:repair-page-pillar', ['content' => $post->id])->assertFailed();
});
