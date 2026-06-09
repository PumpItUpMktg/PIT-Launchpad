<?php

namespace App\Integrations\Embedding;

use RuntimeException;

/**
 * A normalized embeddings failure: a missing key, an OpenAI error envelope, or an
 * auth/quota failure. Surfaced loudly — never swallowed.
 */
class EmbeddingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
