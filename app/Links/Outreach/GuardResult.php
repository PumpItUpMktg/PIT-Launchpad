<?php

namespace App\Links\Outreach;

/**
 * Why a send may or may not go (§ Links): a checklist a person can read, red until every line is green.
 *
 * @property-read list<array{key: string, ok: bool, text: string}> $checks
 */
final class GuardResult
{
    /** @param  list<array{key: string, ok: bool, text: string}>  $checks */
    public function __construct(
        public readonly array $checks,
        public readonly int $usedThisMonth,
        public readonly int $cap,
    ) {}

    public function ok(): bool
    {
        foreach ($this->checks as $c) {
            if (! $c['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return array_values(array_map(fn (array $c): string => $c['text'], array_filter($this->checks, fn (array $c): bool => ! $c['ok'])));
    }
}
