<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Models\Content;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

it('grounds a page on the permalinks of the site\'s other pages as real internal-link targets', function () {
    $page = PageFixture::intakePage(['title' => 'Tankless Install', 'slug' => 'tankless-install']);
    Content::factory()->page()->create(['site_id' => $page->site_id, 'title' => 'Drain Cleaning', 'slug' => 'drain-cleaning']);
    Content::factory()->page()->create(['site_id' => $page->site_id, 'title' => 'Austin', 'slug' => 'austin-tx']);

    $grounding = (new PageGroundingAssembler)->assemble($page);

    $paths = collect($grounding->relatedLinks)->pluck('path');
    expect($paths)->toContain('/drain-cleaning')
        ->and($paths)->toContain('/austin-tx')
        ->and($paths)->not->toContain('/tankless-install'); // never links to itself
});

it('writes the internal-link paths into the drafter prompt so links point at final URLs', function () {
    $page = PageFixture::intakePage(['title' => 'Tankless Install', 'slug' => 'tankless-install']);
    Content::factory()->page()->create(['site_id' => $page->site_id, 'title' => 'Drain Cleaning', 'slug' => 'drain-cleaning']);

    $grounding = (new PageGroundingAssembler)->assemble($page);
    $prompt = (new PageDrafter(new DraftCall(new FakeClaudeClient('x'))))->preview($grounding)['prompt'];

    expect($prompt)->toContain('INTERNAL LINKS')
        ->and($prompt)->toContain('/drain-cleaning')
        ->and($prompt)->toContain('never invent a URL');
});

it('passes no link targets when the site has only the one page', function () {
    $page = PageFixture::intakePage();

    $grounding = (new PageGroundingAssembler)->assemble($page);

    expect($grounding->relatedLinks)->toBe([]);
});
