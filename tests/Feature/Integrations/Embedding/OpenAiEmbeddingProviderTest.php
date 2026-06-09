<?php

use App\Integrations\Embedding\EmbeddingException;
use App\Integrations\Embedding\OpenAiEmbeddingProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function embeddingProvider(int $dimensions = 1536, int $maxInputTokens = 8191): OpenAiEmbeddingProvider
{
    return new OpenAiEmbeddingProvider(
        app(Http::class),
        app(Cache::class),
        'sk-test',
        'https://api.openai.com/v1',
        'text-embedding-3-small',
        $dimensions,
        $maxInputTokens,
        720,
        30,
    );
}

/**
 * Build an OpenAI embeddings response. $vectors is index => vector; pass them in
 * any order to prove index-based re-association.
 *
 * @param  array<int, list<float>>  $vectors
 * @return array<string, mixed>
 */
function embeddingResponse(array $vectors): array
{
    $data = [];
    foreach ($vectors as $index => $vector) {
        $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $vector];
    }

    return [
        'object' => 'list',
        'data' => $data,
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
    ];
}

it('builds a request with model, array input, dimensions and float encoding', function () {
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(embeddingResponse([0 => [0.1, 0.2, 0.3]])),
    ]);

    embeddingProvider(dimensions: 3)->embed('hello world');

    HttpFacade::assertSent(function ($request) {
        return str_ends_with($request->url(), '/embeddings')
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'text-embedding-3-small'
            && $request['input'] === ['hello world']   // array input, not a bare string
            && $request['encoding_format'] === 'float'
            && $request['dimensions'] === 3;
    });
});

it('parses a single embedding to a list of floats', function () {
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(embeddingResponse([0 => [0.5, -0.25, 0.75]])),
    ]);

    expect(embeddingProvider(dimensions: 3)->embed('hello'))->toBe([0.5, -0.25, 0.75]);
});

it('re-associates batched vectors by response index, not array position', function () {
    // Response deliberately out of order: index 1 first, then 0, then 2.
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(embeddingResponse([
            1 => [1.0, 1.1],
            0 => [0.0, 0.1],
            2 => [2.0, 2.1],
        ])),
    ]);

    $vectors = embeddingProvider(dimensions: 2)->embedMany(['a', 'b', 'c']);

    expect($vectors[0])->toBe([0.0, 0.1])   // 'a' → index 0
        ->and($vectors[1])->toBe([1.0, 1.1]) // 'b' → index 1
        ->and($vectors[2])->toBe([2.0, 2.1]); // 'c' → index 2
});

it('truncates an over-cap input to stay under the per-input token limit', function () {
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(embeddingResponse([0 => [0.1]])),
    ]);

    // maxInputTokens=10 → 40-char cap. Send 200 chars.
    embeddingProvider(dimensions: 1, maxInputTokens: 10)->embed(str_repeat('x', 200));

    HttpFacade::assertSent(fn ($request) => mb_strlen($request['input'][0]) === 40);
});

it('does not re-embed unchanged text (idempotency cache)', function () {
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(embeddingResponse([0 => [0.1, 0.2]])),
    ]);

    $provider = embeddingProvider(dimensions: 2);
    $provider->embed('same text');
    $provider->embed('same text'); // served from cache

    HttpFacade::assertSentCount(1);
});

it('fails loudly and fatally on an auth error', function () {
    HttpFacade::fake([
        '*/embeddings' => HttpFacade::response(['error' => ['message' => 'Invalid API key', 'code' => 'invalid_api_key']], 401),
    ]);

    try {
        embeddingProvider()->embed('hello');
        $this->fail('expected EmbeddingException');
    } catch (EmbeddingException $e) {
        expect($e->fatal)->toBeTrue()
            ->and($e->statusCode)->toBe(401)
            ->and($e->getMessage())->toContain('Invalid API key');
    }
});

it('throws without any network call when the key is blank', function () {
    HttpFacade::fake();

    $provider = new OpenAiEmbeddingProvider(
        app(Http::class), app(Cache::class), '', 'https://api.openai.com/v1', 'text-embedding-3-small', 1536,
    );

    try {
        $provider->embed('hello');
        $this->fail('expected EmbeddingException');
    } catch (EmbeddingException $e) {
        expect($e->fatal)->toBeTrue();
    }

    HttpFacade::assertNothingSent();
});
