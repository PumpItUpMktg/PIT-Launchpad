<?php

use App\Integrations\AiSearch\PerplexityEngine;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function pplx(?string $key = 'pk-test'): PerplexityEngine
{
    return new PerplexityEngine(app(Factory::class), $key, 'https://api.perplexity.ai', 'sonar');
}

it('is disabled without an API key', function () {
    expect(pplx(null)->enabled())->toBeFalse()
        ->and(pplx(null)->ask('anything'))->toBeNull()
        ->and(pplx()->key())->toBe('perplexity');
});

it('parses Perplexity content + search_results + citations, deduped', function () {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'choices' => [['message' => ['content' => 'Sump Pump Gurus is recommended.']]],
        'search_results' => [['title' => 'SPG', 'url' => 'https://sumppumpgurus.com/', 'date' => '2026-01-01']],
        'citations' => ['https://sumppumpgurus.com/', 'https://rival.example/'], // first is a dup of search_results
    ])]);

    $answer = pplx()->ask('best sump pump repair in Union NJ');

    expect($answer)->not->toBeNull()
        ->and($answer->text)->toBe('Sump Pump Gurus is recommended.')
        ->and($answer->citationUrls())->toBe(['https://sumppumpgurus.com/', 'https://rival.example/']);
});

it('returns null on an API error or empty content', function () {
    Http::fake(['api.perplexity.ai/*' => Http::response('', 500)]);
    expect(pplx()->ask('x'))->toBeNull();

    Http::fake(['api.perplexity.ai/*' => Http::response(['choices' => [['message' => ['content' => '']]]])]);
    expect(pplx()->ask('x'))->toBeNull();
});
