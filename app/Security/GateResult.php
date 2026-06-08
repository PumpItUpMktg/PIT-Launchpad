<?php

namespace App\Security;

/**
 * The structured outcome of the launch gate: an overall pass plus the
 * per-credential / per-secret checklist behind it. The launch flow blocks unless
 * `passed` is true; the admin renders `checks` as a red-until-green list.
 */
final class GateResult
{
    /**
     * @param  list<GateCheck>  $checks
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $checks,
    ) {}

    /**
     * @param  list<GateCheck>  $checks
     */
    public static function fromChecks(array $checks): self
    {
        foreach ($checks as $check) {
            if (! $check->passed) {
                return new self(false, $checks);
            }
        }

        return new self(true, $checks);
    }

    /**
     * @return list<GateCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (GateCheck $c) => ! $c->passed));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'checks' => array_map(fn (GateCheck $c) => $c->toArray(), $this->checks),
        ];
    }
}
