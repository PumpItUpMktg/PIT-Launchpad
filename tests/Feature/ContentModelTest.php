<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Database\QueryException;

test('content casts enums and json', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id]);

    expect($page->kind)->toBe(ContentKind::Page)
        ->and($page->page_type)->toBe(PageType::Service)
        ->and($page->status)->toBe(ContentStatus::Candidate)
        ->and($page->meta)->toBeArray();
});

test('a post has an intake type and no page type', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id]);

    expect($post->kind)->toBe(ContentKind::Post)
        ->and($post->page_type)->toBeNull()
        ->and($post->intake_type)->not->toBeNull();
});

test('content is soft deleted', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id]);

    $page->delete();

    expect(Content::find($page->id))->toBeNull()
        ->and(Content::withTrashed()->find($page->id))->not->toBeNull();
});

test('the slug is unique within a site but reusable across sites', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    Content::factory()->create(['site_id' => $siteA->id, 'slug' => 'shared-slug']);
    Content::factory()->create(['site_id' => $siteB->id, 'slug' => 'shared-slug']);

    expect(Content::withoutGlobalScopes()->where('slug', 'shared-slug')->count())->toBe(2);

    expect(fn () => Content::factory()->create(['site_id' => $siteA->id, 'slug' => 'shared-slug']))
        ->toThrow(QueryException::class);
});
