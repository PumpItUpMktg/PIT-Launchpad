<?php

namespace App\Branding;

/**
 * A generated (and guard-validated) brand: the palette + typography pairing the
 * review screen presents, with the rationale that justifies the choices and the
 * list of any adjustments the validation guard had to make (a hallucinated font
 * swapped for a safe default, a low-contrast text color corrected). It round-trips
 * to the SiteBranding shape the BrandKitAssembler already consumes.
 */
class GeneratedBrand
{
    /**
     * @param  array{primary: string, accent: string, text: string}  $palette
     * @param  array{heading: string, body: string}  $typography
     * @param  list<string>  $adjustments
     */
    public function __construct(
        public readonly array $palette,
        public readonly array $typography,
        public readonly string $rationale,
        public readonly array $adjustments = [],
    ) {}

    /**
     * The §1 SiteBranding shape (palette + typography columns). The assembler maps
     * typography.heading→Global-Kit primary and body→text; palette keys map straight
     * to the system color slots.
     *
     * @return array{palette: array<string, string>, typography: array<string, string>}
     */
    public function toBranding(): array
    {
        return [
            'palette' => $this->palette,
            'typography' => $this->typography,
        ];
    }
}
