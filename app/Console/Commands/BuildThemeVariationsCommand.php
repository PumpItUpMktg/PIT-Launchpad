<?php

namespace App\Console\Commands;

use App\Styling\StyleVariation;
use App\Styling\VariationThemeJson;
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

    public function __construct(private readonly VariationThemeJson $builder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dir = base_path(self::STYLES_DIR);
        $check = (bool) $this->option('check');
        $stale = [];

        foreach (StyleVariation::cases() as $variation) {
            $path = $dir.'/'.$variation->themeVariationSlug().'.json';
            $json = $this->builder->json($variation);

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
}
