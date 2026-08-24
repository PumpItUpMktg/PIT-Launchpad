<?php

use App\Integrations\AiSearch\ClaudeWebSearchEngine;
use Illuminate\Support\Facades\Http;

function engine(?string $key = 'sk-test'): ClaudeWebSearchEngine
{
    return new ClaudeWebSearchEngine(app(\Illuminate\Http\Client\Factory::class), $key, 'https://api.anthropic.com', 'claude-sonnet-4-6');
}

it('is disabled without an API key', function () {
    expect(engine(null)->enabled())->toBeFalse()
        ->and(engine(null)->ask('anything'))->toBeNull();
});

it('flattens Claude web-search blocks into answer text + deduped citations', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [
            ['type' => 'server_tool_use', 'name' => 'web_search'],
            ['type' => 'web_search_tool_result', 'content' => [
                ['type' => 'web_search_result', 'url' => 'https://sumppumpgurus.com/', 'title' => 'Sump Pump Gurus'],
                ['type' => 'web_search_result', 'url' => 'https://rival.example/', 'title' => 'Rival'],
            ]],
            ['type' => 'text', 'text' => 'For sump pump repair, Sump Pump Gurus is well reviewed.',
                'citations' => [['url' => 'https://sumppumpgurus.com/', 'title' => 'Sump Pump Gurus']]], // dup of above
        ],
    ])]);

    $answer = engine()->ask('best sump pump repair in Union NJ');

    expect($answer)->not->toBeNull()
        ->and($answer->text)->toBe('For sump pump repair, Sump Pump Gurus is well reviewed.')
        ->and($answer->citationUrls())->toBe(['https://sumppumpgurus.com/', 'https://rival.example/']); // deduped
});

it('returns null on an API error', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('', 500)]);

    expect(engine()->ask('x'))->toBeNull();
});
