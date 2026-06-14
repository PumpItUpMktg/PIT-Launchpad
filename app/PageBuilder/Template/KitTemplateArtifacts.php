<?php

namespace App\PageBuilder\Template;

/**
 * Locates and loads the bound Elementor template artifacts the engine pushes to a
 * tenant's Theme Builder (the H2 import-on-provision payload). One shared resolver
 * so the push command and the launch orchestrator agree on where artifacts live
 * and which one wins.
 *
 * Resolution order per kit, most-specific first:
 *   1. {kit}.bound.elementor.json   — the production binder output (designer's
 *      styled template + lp/* bindings) — preferred when present.
 *   2. {kit}.{mode}.elementor.json  — the generator fallback (native | shortcode).
 *
 * Artifacts live in the repo for now (database/data/wireframe-kits/templates); a
 * later step moves them to R2/DB behind this same interface with no caller change.
 */
final class KitTemplateArtifacts
{
    public function __construct(private readonly ?string $dir = null) {}

    public function dir(): string
    {
        return $this->dir ?? database_path('data/wireframe-kits/templates');
    }

    /**
     * The resolved artifact path for a kit, or null if none exists.
     */
    public function path(string $kit, string $mode = 'native'): ?string
    {
        $candidates = [
            $this->dir().'/'.$kit.'.bound.elementor.json',
            $this->dir().'/'.$kit.'.'.$mode.'.elementor.json',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Load a kit's artifact as the decoded Elementor export array (version / title /
     * type / content / page_settings), or null if missing/unparseable.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $kit, string $mode = 'native'): ?array
    {
        $path = $this->path($kit, $mode);
        if ($path === null) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The kits that have at least one artifact on disk (deduped, sorted).
     *
     * @return list<string>
     */
    public function availableKits(): array
    {
        $kits = [];
        foreach (glob($this->dir().'/*.elementor.json') ?: [] as $file) {
            $base = basename($file, '.elementor.json');
            // Strip the trailing .bound / .native / .shortcode mode segment.
            $kit = preg_replace('/\.(bound|native|shortcode)$/', '', $base);
            if (is_string($kit) && $kit !== '') {
                $kits[$kit] = true;
            }
        }

        $names = array_keys($kits);
        sort($names);

        return $names;
    }
}
