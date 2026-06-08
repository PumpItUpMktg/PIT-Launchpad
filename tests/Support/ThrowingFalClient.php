<?php

namespace Tests\Support;

use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalException;
use App\Integrations\Fal\FalImage;

/**
 * A fal adapter that always fails — for exercising the render pipeline's bounded
 * retries and render_failed terminal.
 */
class ThrowingFalClient implements FalClient
{
    public int $calls = 0;

    public function generate(string $prompt, array $options = []): FalImage
    {
        $this->calls++;

        throw new FalException('fal returned HTTP 500');
    }
}
