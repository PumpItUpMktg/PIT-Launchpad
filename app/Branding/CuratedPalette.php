<?php

namespace App\Branding;

/**
 * One vetted entry in the curated palette library: the full --wf-* token set
 * (explicit hex, no runtime math) for a scheme, plus a font pairing and the
 * recommender signals (form affinity + industry tags). It round-trips to the
 * BrandCandidate the picker (§5) renders and BrandStudio saves/pushes.
 */
final class CuratedPalette
{
    /**
     * @param  array<string, string>  $tokens  the nine color slots → hex
     * @param  list<string>  $industryTags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Scheme $scheme,
        public readonly string $formAffinity,
        public readonly array $industryTags,
        public readonly array $tokens,
        public readonly string $fontHeading,
        public readonly string $fontBody,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            scheme: Scheme::fromString($row['scheme'] ?? 'light'),
            formAffinity: (string) ($row['form_affinity'] ?? 'trust'),
            industryTags: array_values(array_filter((array) ($row['industry_tags'] ?? []), 'is_string')),
            tokens: array_map('strval', is_array($row['tokens'] ?? null) ? $row['tokens'] : []),
            fontHeading: (string) ($row['font_heading'] ?? 'Inter'),
            fontBody: (string) ($row['font_body'] ?? 'Inter'),
        );
    }

    /**
     * The contrast failures across the shared surface pairings PLUS the curated
     * on_accent against the accent (the rendered CTA text, which the library pins
     * rather than auto-picks). Empty = certified contrast-safe.
     *
     * @return list<array{pair: string, ratio: float, min: float}>
     */
    public function contrastFailures(): array
    {
        $failures = ContrastMatrix::failures($this->tokens);

        $onAccent = $this->tokens['on_accent'] ?? '';
        $accent = $this->tokens['accent'] ?? '';
        $ratio = ColorContrast::ratio($onAccent, $accent);
        if ($ratio < ContrastMatrix::TEXT_MIN) {
            $failures[] = ['pair' => 'on_accent-on-accent', 'ratio' => round($ratio, 2), 'min' => ContrastMatrix::TEXT_MIN];
        }

        return $failures;
    }

    /**
     * As the BrandCandidate the picker + save path already consume. Adjustments are
     * empty — a curated palette is pre-vetted, never nudged.
     */
    public function toCandidate(bool $recommended = false, string $rationale = ''): BrandCandidate
    {
        return new BrandCandidate(
            palette: $this->tokens,
            typography: ['heading' => $this->fontHeading, 'body' => $this->fontBody],
            rationale: $rationale,
            recommended: $recommended,
            adjustments: [],
            form: $this->formAffinity,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scheme' => $this->scheme->value,
            'form_affinity' => $this->formAffinity,
            'industry_tags' => $this->industryTags,
            'tokens' => $this->tokens,
            'font_heading' => $this->fontHeading,
            'font_body' => $this->fontBody,
        ];
    }
}
