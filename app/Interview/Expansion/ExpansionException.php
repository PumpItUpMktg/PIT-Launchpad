<?php

namespace App\Interview\Expansion;

use RuntimeException;

/**
 * Thrown when the model's candidate tree cannot be validated after the allowed
 * retries. Like the seed extractor, the expander fails loud rather than emit a
 * malformed or fabricated tree.
 */
final class ExpansionException extends RuntimeException
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
