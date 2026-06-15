<?php

namespace App\Console\Commands;

use App\Branding\PaletteLibrary;
use App\Branding\Scheme;
use Illuminate\Console\Command;

/**
 * The curated-palette vetting tool (read-only): lists each library set with its
 * tokens, fonts, and the ContrastMatrix pairings result, so a failing pairing is
 * caught before a palette is locked. A non-zero exit if any listed set fails — the
 * certification gate for the library.
 *
 *   launchpad:palette-library [--scheme=light|dark]
 */
class PaletteLibraryCommand extends Command
{
    protected $signature = 'launchpad:palette-library {--scheme= : filter to light|dark}';

    protected $description = 'List + contrast-check the curated palette library (read-only certification).';

    public function handle(PaletteLibrary $library): int
    {
        $filter = (string) $this->option('scheme');
        $palettes = in_array($filter, ['light', 'dark'], true)
            ? $library->forScheme(Scheme::from($filter))
            : $library->all();

        if ($palettes === []) {
            $this->warn('No palettes in the library'.($filter !== '' ? " for scheme={$filter}." : '.'));

            return self::SUCCESS;
        }

        $anyFailed = false;
        foreach ($palettes as $palette) {
            $failures = $palette->contrastFailures();
            $status = $failures === [] ? '<info>PASS</info>' : '<error>FAIL</error>';
            $this->line('');
            $this->line(sprintf('%s  %s  [%s · %s]  %s / %s', $status, $palette->name, $palette->scheme->value, $palette->formAffinity, $palette->fontHeading, $palette->fontBody));
            $this->line('  '.collect($palette->tokens)->map(fn ($v, $k) => "{$k}={$v}")->implode('  '));
            foreach ($failures as $f) {
                $anyFailed = true;
                $this->error(sprintf('  FAIL %s — %.2f:1 (needs %.1f)', $f['pair'], $f['ratio'], $f['min']));
            }
        }

        $this->line('');
        $this->info(sprintf('%d palette(s) listed.', count($palettes)));

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
