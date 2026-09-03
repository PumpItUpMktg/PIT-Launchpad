<?php

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\MetaBlobAssembler;

/** @return array<int, array{name: string, url: string}> */
function crumbs(MetaBlobAssembler $asm, Content $content): array
{
    $r = new ReflectionMethod($asm, 'breadcrumbs');
    $r->setAccessible(true);

    return $r->invoke($asm, $content);
}

it('renders a valid 3-item crumb for a post whose silo is SOFT-DELETED but resolves a live index page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Commercial Pump Services']);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'commercial-pump-services',
    ]);
    $post = Content::factory()->post()->published()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'title' => 'Why Local School Repairs',
    ]);
    $silo->delete(); // soft-delete the silo — the silo_id column on content is unchanged

    $asm = app(MetaBlobAssembler::class);
    $crumbs = crumbs($asm, $post->fresh());

    expect($crumbs)->toHaveCount(3)
        ->and($crumbs[1]['name'])->toBe('Commercial Pump Services')
        ->and($crumbs[1]['url'])->toBe('https://spg.example/commercial-pump-services/')  // every intermediate crumb has a URL
        ->and($asm->resolvesSiloTop($post->fresh()))->toBeTrue();                          // the probe no longer under-reports
});

it('still collapses to a valid 2-item crumb when the silo has no resolvable live page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Orphan Silo']); // no live page carries this silo_id
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'title' => 'A Post']);

    $asm = app(MetaBlobAssembler::class);
    $crumbs = crumbs($asm, $post->fresh());

    expect($crumbs)->toHaveCount(2) // Home → leaf; never a name without a URL
        ->and($asm->resolvesSiloTop($post->fresh()))->toBeFalse();
});

it('emits a valid 2-item crumb for a post with no silo (the null-silo control)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'silo_id' => null, 'title' => 'No Silo']);

    expect(crumbs(app(MetaBlobAssembler::class), $post->fresh()))->toHaveCount(2);
});
