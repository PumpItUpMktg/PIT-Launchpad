<?php

namespace App\Branding;

/**
 * The loader for the curated palette library (config/palette_library.php) — the
 * closed set the recommender selects from. Read-only; the data is vetted in the
 * repo. Mirrors FontCatalog's role: a bounded, certified set the model is
 * constrained to.
 */
class PaletteLibrary
{
    /** @var array<string, mixed>|null */
    private ?array $config;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config;
    }

    /**
     * @return list<CuratedPalette>
     */
    public function all(): array
    {
        $rows = (array) ($this->config()['palettes'] ?? []);

        return array_values(array_map(
            fn (array $row) => CuratedPalette::fromArray($row),
            array_filter($rows, 'is_array'),
        ));
    }

    /**
     * @return list<CuratedPalette>
     */
    public function forScheme(Scheme $scheme): array
    {
        return array_values(array_filter($this->all(), fn (CuratedPalette $p) => $p->scheme === $scheme));
    }

    public function find(string $id): ?CuratedPalette
    {
        foreach ($this->all() as $palette) {
            if ($palette->id === $id) {
                return $palette;
            }
        }

        return null;
    }

    /**
     * The deterministic fallback palette for a scheme — the configured default id, or
     * the first palette in that scheme.
     */
    public function default(Scheme $scheme): ?CuratedPalette
    {
        $defaultId = (string) (($this->config()['defaults'] ?? [])[$scheme->value] ?? '');
        $byId = $defaultId !== '' ? $this->find($defaultId) : null;

        if ($byId !== null && $byId->scheme === $scheme) {
            return $byId;
        }

        return $this->forScheme($scheme)[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config ??= (array) config('palette_library', []);
    }
}
