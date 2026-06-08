<?php

namespace App\Onboarding;

use RuntimeException;

/**
 * Thrown when launch is attempted before the completeness gate is satisfied.
 */
class IncompleteOnboardingException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Onboarding is incomplete: '.implode(', ', $missing));
    }
}
