<?php

namespace App\Links\Outreach;

use RuntimeException;

/** The guard said no (§ Links). `reasons` are the lines to show the person. */
final class OutreachRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
