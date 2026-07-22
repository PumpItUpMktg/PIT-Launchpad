<?php

namespace App\Console\Commands;

use App\Styling\StyleVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Dev tooling: regenerate the block theme's `/styles/{slug}.json` style-variation files from the
 * {@see StyleVariation} enum — the single source of truth for the palettes. Each variation gets its
 * full six-role palette (base / surface / text → contrast / primary / highlight → accent / button,
 * plus the derived muted / border / on-accent / on-button), its heading typeface, and its radius +
 * tracking. Run it after editing a palette in the enum, then rebuild the theme zip.
 *
 * Theme files never drift from the enum because they are GENERATED from it; a test asserts they match.
 */
class BuildThemeVariationsCommand extends Command
{
    protected $signature = 'launchpad:build-theme-variations {--check : Fail (non-zero) if any file is out of date, writing nothing}';

    protected $description = 'Generate the block theme style-variation JSON files from the StyleVariation enum.';

    private const STYLES_DIR = 'wordpress-theme/launchpad-blocks/styles';

    /** @var array<string, array{family: string, weight: string, file: string}> */
    private const FONTS = [
        'Archivo' => ['family' => 'Archivo, system-ui, sans-serif', 'weight' => '800', 'file' => 'archivo-800.woff2'],
        'Manrope' => ['family' => 'Manrope, system-ui, sans-serif', 'weight' => '700', 'file' => 'manrope-700.woff2'],
        'Bricolage Grotesque' => ['family' => '"Bricolage Grotesque", system-ui, sans-serif', 'weight' => '700', 'file' => 'bricolage-grotesque-700.woff2'],
    ];

    public function handle(): int
    {
        $dir = base_path(self::STYLES_DIR);
        $check = (bool) $this->option('check');
        $stale = [];

        foreach (StyleVariation::cases() as $variation) {
            $path = $dir.'/'.$variation->themeVariationSlug().'.json';
            $json = $this->render($variation);

            $current = File::exists($path) ? rtrim((string) File::get($path), "\n") : null;
            if ($current === $json) {
                continue;
            }

            if ($check) {
                $stale[] = $variation->themeVariationSlug().'.json';

                continue;
            }

            File::put($path, $json."\n");
            $this->line('  wrote '.$variation->themeVariationSlug().'.json');
        }

        if ($check && $stale !== []) {
            $this->error('Out of date: '.implode(', ', $stale).' — run launchpad:build-theme-variations.');

            return self::FAILURE;
        }

        $this->info($check ? 'All style variations are up to date.' : 'Generated '.count(StyleVariation::cases()).' style variations.');

        return self::SUCCESS;
    }

    private function render(StyleVariation $variation): string
    {
        $p = $variation->palette();
        $t = $variation->typography();
        $font = self::FONTS[$t['heading_font']];

        $doc = [
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

        return (string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
