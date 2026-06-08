<?php

namespace App\Integrations\Fal;

/**
 * The image-generation seam (committed vendor — fal.ai). Callers depend on this
 * interface; the real adapter is hardened with HTTP timeouts and normalized
 * errors, and tests bind a deterministic mock so no network call is made.
 */
interface FalClient
{
    /**
     * Generate an image for a prompt.
     *
     * @param  array<string, mixed>  $options  e.g. width/height hints
     *
     * @throws FalException on timeout, transport error, or a non-success response
     */
    public function generate(string $prompt, array $options = []): FalImage;
}
