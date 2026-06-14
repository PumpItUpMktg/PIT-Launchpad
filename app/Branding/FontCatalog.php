<?php

namespace App\Branding;

/**
 * The real, loadable Google Font families — the validation set the brand generator
 * checks every returned family against. Membership is case-insensitive and
 * whitespace-tolerant, so "  inter " resolves to the canonical "Inter". Any family
 * NOT in the catalog is treated as a hallucination/misspelling and the caller falls
 * back to a safe default, so an invented family can never silently break the
 * Elementor Global Kit cascade.
 *
 * The list is bundled (database/data/google-fonts.json) and read once. It is a
 * broad working subset today; it can be regenerated to the full Google Fonts API
 * roster with no code change — this class only cares that a returned family is a
 * real, loadable one.
 */
class FontCatalog
{
    /** @var array<string, string>|null  normalized => canonical */
    private ?array $index = null;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Resolve a family name to its canonical Google Fonts spelling, or null when
     * it is not a known loadable family.
     */
    public function canonical(string $family): ?string
    {
        $key = $this->normalize($family);
        if ($key === '') {
            return null;
        }

        return $this->index()[$key] ?? null;
    }

    public function has(string $family): bool
    {
        return $this->canonical($family) !== null;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values($this->index());
    }

    /**
     * @return array<string, string>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $file = $this->path ?? database_path('data/google-fonts.json');
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $families = is_array($decoded) && isset($decoded['families']) && is_array($decoded['families'])
            ? $decoded['families']
            : [];

        $index = [];
        foreach ($families as $family) {
            if (! is_string($family) || trim($family) === '') {
                continue;
            }
            $index[$this->normalize($family)] = trim($family);
        }

        return $this->index = $index;
    }

    private function normalize(string $family): string
    {
        // Collapse internal whitespace and lowercase, so case/spacing variants of a
        // real family still resolve (the model is loose with capitalization).
        $family = trim((string) preg_replace('/\s+/', ' ', $family));

        return mb_strtolower($family);
    }
}
