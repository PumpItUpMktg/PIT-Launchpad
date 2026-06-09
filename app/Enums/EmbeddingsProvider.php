<?php

namespace App\Enums;

/**
 * Selects the live embeddings backend behind the §6 EmbeddingProvider contract.
 * Only OpenAI today; the switch exists for symmetry with the other vendor
 * selectors and so a second backend can bind later without a structural change.
 */
enum EmbeddingsProvider: string
{
    case OpenAi = 'openai';
}
