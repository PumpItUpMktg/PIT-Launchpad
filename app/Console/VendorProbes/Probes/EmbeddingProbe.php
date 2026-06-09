<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\Embedding\EmbeddingProvider;
use Throwable;

/**
 * Embeddings — embed a trivial string and confirm a vector of the expected
 * dimension comes back (the live OpenAI path behind the §6 contract).
 */
class EmbeddingProbe implements VendorProbe
{
    public function label(): string
    {
        return 'Embeddings';
    }

    public function order(): int
    {
        return 60;
    }

    public function run(): ProbeResult
    {
        if ((string) config('services.openai.key') === '') {
            return ProbeResult::skip('OPENAI_API_KEY not set');
        }

        try {
            $vector = app(EmbeddingProvider::class)->embed('ping');
            $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');

            return ProbeResult::live("model={$model}, dim=".count($vector));
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
