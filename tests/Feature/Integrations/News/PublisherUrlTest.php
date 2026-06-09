<?php

use App\Integrations\News\RssFeed;
use Tests\Support\Feeds;

it('resolves a google.com/url wrapper to the publisher url', function () {
    expect(RssFeed::publisherUrl('https://www.google.com/url?url=https://tribune.com/story&ct=ga'))
        ->toBe('https://tribune.com/story');
});

it('resolves a legacy base64 article id to the publisher url', function () {
    expect(RssFeed::publisherUrl(Feeds::gnewsCleanLink('https://outlet.com/article')))
        ->toBe('https://outlet.com/article');
});

it('returns null for a modern opaque google news token (no decoder)', function () {
    expect(RssFeed::publisherUrl(Feeds::gnewsOpaqueLink()))->toBeNull();
});

it('passes a direct publisher link through unchanged', function () {
    expect(RssFeed::publisherUrl('https://techcrunch.com/2026/gadget'))
        ->toBe('https://techcrunch.com/2026/gadget');
});

it('returns null for an empty link', function () {
    expect(RssFeed::publisherUrl(''))->toBeNull();
});
