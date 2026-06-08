<?php

namespace App\SiloCreator;

use RuntimeException;

/**
 * Thrown when a silo proposal contains geo/city terms and cannot be committed.
 */
class GeoNeutralViolationException extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly string $siloName, public readonly array $violations)
    {
        parent::__construct("Silo [{$siloName}] is not geo-neutral: ".implode(', ', $violations));
    }
}
