<?php

namespace App\PageBuilder\Library;

/**
 * The wireframe-spec sidecar as a runtime contract: maps a stable `wf-*` hook to its
 * declared type (heading / text / image / button / accordion / …) and char-range /
 * image-size. The injector consults it to refuse a type-mismatched value (no image
 * into a heading hook) and to warn on char-range overflow.
 */
final class Sidecar
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $spec = null;

    public function __construct(private readonly ?string $path = null) {}

    public function type(string $hook): ?string
    {
        $t = $this->spec()[$hook]['type'] ?? null;

        return is_string($t) ? $t : null;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function charRange(string $hook): ?array
    {
        $r = $this->spec()[$hook]['char_range'] ?? null;

        return is_array($r) && count($r) === 2 ? [(int) $r[0], (int) $r[1]] : null;
    }

    public function has(string $hook): bool
    {
        return isset($this->spec()[$hook]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function spec(): array
    {
        if ($this->spec !== null) {
            return $this->spec;
        }

        $file = $this->path ?? database_path('data/wireframe-library/wireframe-spec.json');
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->spec = is_array($decoded) ? $decoded : [];
    }
}
