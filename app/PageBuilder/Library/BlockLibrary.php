<?php

namespace App\PageBuilder\Library;

/**
 * Read-only access to the vendored wireframe library — the composer's structural
 * source of truth (database/data/wireframe-library). Returns the raw element tree
 * of a block / page assembly exactly as generated; reconciliation to the live
 * Elementor target is a separate concern (TargetNormalizer + the target profile),
 * so the library JSON is never mutated.
 */
final class BlockLibrary
{
    public function __construct(private readonly ?string $dir = null) {}

    public function dir(): string
    {
        return $this->dir ?? database_path('data/wireframe-library');
    }

    /**
     * A block's element tree (the `content` array), by short name — `faq` →
     * blocks/block-faq.json. Empty when the block is absent.
     *
     * @return list<array<string, mixed>>
     */
    public function block(string $name): array
    {
        return $this->contentOf($this->dir().'/blocks/block-'.$name.'.json');
    }

    /**
     * A page assembly's element tree (the body blocks, in order), by short name —
     * `service` → pages/page-service.json.
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $name): array
    {
        return $this->contentOf($this->dir().'/pages/page-'.$name.'.json');
    }

    /**
     * @return list<string>
     */
    public function blockNames(): array
    {
        $names = [];
        foreach (glob($this->dir().'/blocks/block-*.json') ?: [] as $path) {
            $names[] = (string) preg_replace('/^block-(.+)\.json$/', '$1', basename($path));
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentOf(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && isset($decoded['content']) && is_array($decoded['content'])
            ? array_values($decoded['content'])
            : [];
    }
}
