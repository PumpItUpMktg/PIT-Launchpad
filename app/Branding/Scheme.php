<?php

namespace App\Branding;

/**
 * The brand COLOR SCHEME — Light or Dark — the second brand axis, orthogonal to the
 * form (structure) preset. Form never implies a scheme; scheme is the only thing
 * that flips light/dark. The client chooses it explicitly; the generator emits a
 * full palette conformed to it.
 */
enum Scheme: string
{
    case Light = 'light';
    case Dark = 'dark';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Light;
    }

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
        };
    }

    public function other(): self
    {
        return $this === self::Light ? self::Dark : self::Light;
    }
}
