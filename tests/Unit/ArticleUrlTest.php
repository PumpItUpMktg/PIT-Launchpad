<?php

use App\ContentEngine\ArticleUrl;

it('normalizes an article URL to a canonical, scheme-agnostic dedup key', function () {
    // www stripped, query + fragment dropped, path preserved.
    expect(ArticleUrl::key('https://www.nj.com/news/basement-story?utm_source=x&utm_medium=y#top'))
        ->toBe('nj.com/news/basement-story');
    // http vs https never split a story; a trailing slash is dropped.
    expect(ArticleUrl::key('http://nj.com/news/basement-story/'))->toBe('nj.com/news/basement-story');
    // Host + path lower-cased.
    expect(ArticleUrl::key('https://NJ.com/News/Basement-Story'))->toBe('nj.com/news/basement-story');
    // Scheme-less link still resolves to a host.
    expect(ArticleUrl::key('nj.com/x'))->toBe('nj.com/x');
    // Homepage (no path) → bare host.
    expect(ArticleUrl::key('https://nj.com'))->toBe('nj.com');
});

it('returns null when there is no usable URL', function () {
    expect(ArticleUrl::key(null))->toBeNull();
    expect(ArticleUrl::key(''))->toBeNull();
    expect(ArticleUrl::key('   '))->toBeNull();
});

it('collapses the same article carried with different tracking/host variance to one key', function () {
    $a = ArticleUrl::key('https://www.example.com/a/b?utm_campaign=news');
    $b = ArticleUrl::key('http://example.com/a/b/');
    $c = ArticleUrl::key('https://example.com/a/b#comments');

    expect($a)->toBe($b)->and($b)->toBe($c);
});
