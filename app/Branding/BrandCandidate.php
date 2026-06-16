<?php

namespace App\Branding;

/**
 * One guard-validated brand candidate the interview surfaces (Phase 3). Holds the
 * FULL palette (SiteBranding keys: primary/secondary/accent/text/text_muted/bg/
 * bg_alt/border) + the heading/body pairing, an industry-grounded rationale, the
 * `recommended` flag, and any adjustments the guards had to make. It round-trips to
 * the SiteBranding shape BrandStudio::save persists and Phase 2's BrandKitAssembler
 * maps to the `--wf-*` push tokens.
 */
final class BrandCandidate
{
    /**
     * @param  array<string, string>  $palette  SiteBranding palette keys → hex
     * @param  array{heading: string, body: string}  $typography
     * @param  list<string>  $adjustments
     */
    public function __construct(
        public readonly array $palette,
        public readonly array $typography,
        public readonly string $rationale,
        public readonly bool $recommended = false,
        public readonly array $adjustments = [],
        public readonly string $form = '',
    ) {}

    public function withRecommended(bool $recommended): self
    {
        return new self($this->palette, $this->typography, $this->rationale, $recommended, $this->adjustments, $this->form);
    }

    /**
     * @return array{palette: array<string, string>, typography: array<string, string>}
     */
    public function toBranding(): array
    {
        return ['palette' => $this->palette, 'typography' => $this->typography];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'palette' => $this->palette,
            'typography' => $this->typography,
            'rationale' => $this->rationale,
            'recommended' => $this->recommended,
            'adjustments' => $this->adjustments,
            'form' => $this->form,
        ];
    }
}
