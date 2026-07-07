<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;

it('makes a page republishable with no WP call when it was never published there', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'wp_post_id' => null, 'status' => ContentStatus::PublishFailed,
    ]);

    $result = app(DeleteFromWordpress::class)->delete($page->fresh());

    expect($result['on_wp'])->toBeFalse()
        ->and($result['deleted'])->toBeFalse();

    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh->status)->toBe(ContentStatus::Approved)
        ->and($fresh->slug)->toBe('drain-cleaning'); // slug preserved for re-publish
});
