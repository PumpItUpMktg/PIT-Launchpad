<?php

namespace App\ColdOutreach;

final readonly class ImportResult
{
    /** @param  list<string>  $errors */
    public function __construct(
        public int $created = 0,
        public int $duplicates = 0,
        public int $invalid = 0,
        public array $errors = [],
    ) {}

    public function summary(): string
    {
        return "{$this->created} added · {$this->duplicates} already in the pipeline · {$this->invalid} skipped (no usable email)";
    }
}
