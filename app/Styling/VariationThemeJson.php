<?php

namespace App\Styling;

/**
 * Builds a curated {@see StyleVariation}'s full theme.json style-variation document from the enum —
 * the SINGLE place the variation's palette + typography + shape tokens are assembled into the
 * WordPress theme.json shape. Two consumers share it so they can never drift:
 *
 *  - {@see \App\Console\Commands\BuildThemeVariationsCommand} writes each doc to the block theme's
 *    `styles/{slug}.json` (what the editor's style picker offers).
 *  - {@see StyleActivator} sends the doc INLINE on the brand push (`activateStyleVariation`), so the
 *    push carries its own palette and does NOT depend on the deployed theme's `styles/{slug}.json`
 *    being current. A stale deployed theme was the cause of curated pushes silently falling back to
 *    the base theme.json palette ("I picked Forest but the site stays blue"): the file the push
 *    relied on carried no palette, so WordPress rendered the base. The inline doc is authoritative.
 */
final class VariationThemeJson
{
    /** @var array<string, array{family: string, weight: string, file: string}> */
    private const FONTS = [
        'Archivo' => ['family' => 'Archivo, system-ui, sans-serif', 'weight' => '800', 'file' => 'archivo-800.woff2'],
        'Manrope' => ['family' => 'Manrope, system-ui, sans-serif', 'weight' => '700', 'file' => 'manrope-700.woff2'],
        'Bricolage Grotesque' => ['family' => '"Bricolage Grotesque", system-ui, sans-serif', 'weight' => '700', 'file' => 'bricolage-grotesque-700.woff2'],
    ];

    /**
     * The full theme.json style-variation document for a curated variation — the six-role palette
     * (base / surface / text→contrast / muted / border / primary / highlight→accent / on-accent /
     * button / on-button), the heading + body font families, and the radius/tracking custom tokens.
     *
     * @return array<string, mixed>
     */
    public function build(StyleVariation $variation): array
    {
        $p = $variation->palette();
        $t = $variation->typography();
        $font = self::FONTS[$t['heading_font']];

        return [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'title' => $variation->label(),
            'description' => $variation->blurb().' '.$t['heading_font'].' headings, '.$t['radius'].' corners.',
            'settings' => [
                'color' => [
                    'palette' => [
                        ['slug' => 'base', 'name' => 'Base', 'color' => $p['base']],
                        ['slug' => 'surface', 'name' => 'Surface', 'color' => $p['surface']],
                        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => $p['text']],
                        ['slug' => 'muted', 'name' => 'Muted', 'color' => $p['muted']],
                        ['slug' => 'border', 'name' => 'Border', 'color' => $p['border']],
                        ['slug' => 'primary', 'name' => 'Primary', 'color' => $p['primary']],
                        ['slug' => 'accent', 'name' => 'Accent', 'color' => $p['highlight']],
                        ['slug' => 'on-accent', 'name' => 'On accent', 'color' => $p['on_accent']],
                        ['slug' => 'button', 'name' => 'Button', 'color' => $p['button']],
                        ['slug' => 'on-button', 'name' => 'On button', 'color' => $p['on_button']],
                    ],
                ],
                'typography' => [
                    'fontFamilies' => [
                        [
                            'slug' => 'heading',
                            'name' => $t['heading_font'],
                            'fontFamily' => $font['family'],
                            'fontFace' => [[
                                'fontFamily' => $t['heading_font'],
                                'fontWeight' => $font['weight'],
                                'fontStyle' => 'normal',
                                'fontDisplay' => 'swap',
                                'src' => ['file:./assets/fonts/'.$font['file']],
                            ]],
                        ],
                        [
                            'slug' => 'body',
                            'name' => 'Inter',
                            'fontFamily' => 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
                            'fontFace' => [
                                ['fontFamily' => 'Inter', 'fontWeight' => '400', 'fontStyle' => 'normal', 'fontDisplay' => 'swap', 'src' => ['file:./assets/fonts/inter-400.woff2']],
                                ['fontFamily' => 'Inter', 'fontWeight' => '600', 'fontStyle' => 'normal', 'fontDisplay' => 'swap', 'src' => ['file:./assets/fonts/inter-600.woff2']],
                            ],
                        ],
                    ],
                ],
                'custom' => [
                    'radius' => $t['radius'],
                    'headingLetterSpacing' => $t['heading_letter_spacing'],
                    'headingWeight' => (string) $t['heading_weight'],
                ],
            ],
        ];
    }

    /**
     * The doc serialized exactly as the shipped `styles/{slug}.json` file — pretty-printed, slashes and
     * unicode unescaped. The build command writes this; a test asserts the shipped files match.
     */
    public function json(StyleVariation $variation): string
    {
        return (string) json_encode($this->build($variation), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
