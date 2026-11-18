<?php

namespace App\Social\Provider;

use RuntimeException;

final class SocialProviderException extends RuntimeException
{
    /** @param  bool  $transient  a timeout / 5xx / per-minute limit — worth one more try; a refusal is not */
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
