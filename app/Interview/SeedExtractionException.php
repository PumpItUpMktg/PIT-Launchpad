<?php

namespace App\Interview;

use RuntimeException;

/**
 * Thrown when the model's output cannot be validated into a well-formed SiloSeed +
 * VoiceProfile after the allowed retries. The extractor NEVER emits a malformed seed
 * — a fabricated trade or empty anchors is a lost-lead liability, so it fails loud.
 */
final class SeedExtractionException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
