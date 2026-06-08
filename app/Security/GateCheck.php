<?php

namespace App\Security;

/**
 * One line in the launch checklist: a named credential/secret, whether it
 * passed, and — when it failed — why. Rendered red until satisfied.
 */
final class GateCheck
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly ?string $reason = null,
    ) {}

    public static function pass(string $key, string $label): self
    {
        return new self($key, $label, true);
    }

    public static function fail(string $key, string $label, string $reason): self
    {
        return new self($key, $label, false, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'reason' => $this->reason,
        ];
    }
}
