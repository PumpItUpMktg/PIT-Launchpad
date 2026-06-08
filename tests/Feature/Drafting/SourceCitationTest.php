<?php

use App\ContentEngine\Drafting\SourceRef;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

test('a canonical source URL is kept as a citable link', function () {
    expect(SourceRef::urlIsCitable('https://industryjournal.example/story'))->toBeTrue();

    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'sources_cited' => [['name' => 'Industry Journal', 'url' => 'https://industryjournal.example/story']],
    ]));

    $content = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site))->content;

    $attr = $content->verification['source_attributions'][0];
    expect($attr['name'])->toBe('Industry Journal')
        ->and($attr['url'])->toBe('https://industryjournal.example/story');
});

test('the source pool URL of truth overrides a model-cited URL for the same source', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    // The request's source pool has Local Tribune at /rebate-story; the model
    // cites a different URL for the same name — the pool's URL wins.
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'sources_cited' => [['name' => 'Local Tribune', 'url' => 'https://localtribune.example/wrong']],
    ]));

    $content = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site))->content;

    expect($content->verification['source_attributions'][0]['url'])
        ->toBe('https://localtribune.example/rebate-story');
});

test('a Google News redirect URL collapses to name-only attribution', function () {
    expect(SourceRef::urlIsCitable('https://news.google.com/rss/articles/CBMiQ'))->toBeFalse();

    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'sources_cited' => [['name' => 'Syndicated Wire', 'url' => 'https://news.google.com/rss/articles/CBMiQ']],
    ]));

    $content = DraftingHarness::engine($claude)->run(DraftingHarness::postRequest($site))->content;

    $attr = $content->verification['source_attributions'][0];
    expect($attr['name'])->toBe('Syndicated Wire')
        ->and($attr['url'])->toBeNull();
});

test('an empty or non-http URL is never citable', function () {
    expect(SourceRef::urlIsCitable(null))->toBeFalse()
        ->and(SourceRef::urlIsCitable(''))->toBeFalse()
        ->and(SourceRef::urlIsCitable('javascript:alert(1)'))->toBeFalse()
        ->and(SourceRef::urlIsCitable('ftp://example.com/x'))->toBeFalse();
});
