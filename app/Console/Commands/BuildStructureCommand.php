<?php

namespace App\Console\Commands;

use App\Build\StructureResetter;
use App\Guided\StepGate;
use App\Jobs\BuildStructure;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Build (or rebuild) a site's structure from the console — the same job the Silos step queues, run here
 * synchronously where there is no FPM clock.
 *
 *   launchpad:build-structure --site=... [--rebuild]
 *
 * The build is Claude clustering plus DataForSEO volume grounding: minutes on a real catalogue. Inside a
 * web request it dies on the request timeout with nothing caught. On a worker it waits its turn on
 * `default`. Here it runs now, holds the terminal while it does, and prints the outcome the job stamped —
 * `ready`, or `failed` with the reason — instead of a spinner.
 *
 * --rebuild clears spokes and queued targets first (the seed and its bound-to-services flag survive),
 * exactly as the step's "rebuild from scratch" does.
 */
class BuildStructureCommand extends Command
{
    protected $signature = 'launchpad:build-structure
        {--site= : Site id or brand name (required)}
        {--rebuild : Clear the existing structure first and build fresh from the seed}';

    protected $description = 'Build a site\'s silo structure synchronously from the console (no request timeout), printing the outcome.';

    public function handle(): int
    {
        $arg = trim((string) $this->option('site'));
        $site = $arg === '' ? null : Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }

        $state = app(StepGate::class)->state($site);

        if ($this->option('rebuild')) {
            app(StructureResetter::class)->reset($site);
            $this->line('Cleared the existing structure (seed kept).');
        }

        $state->update(['structure_status' => 'building']);
        $this->line("<info>{$site->brand_name}</info> — building the structure now (this holds the terminal for a few minutes)…");
        $started = microtime(true);

        BuildStructure::dispatchSync($site->id);   // stamps ready/failed and projects the board itself

        $state->refresh();
        $seconds = (int) round(microtime(true) - $started);
        if ($state->structure_status === 'ready') {
            $this->info(sprintf('Ready in %ds. Open Setup → Silos & keywords to review and approve it.', $seconds));

            return self::SUCCESS;
        }

        $reason = (string) ($state->structure_error ?? '');
        $this->error(sprintf('Failed after %ds%s', $seconds, $reason !== '' ? ': '.$reason : ' — no reason was stamped; check the log.'));
        $this->line('The seed is intact. Fix the cause and re-run; add --rebuild if a half-built tree was left behind.');

        return self::FAILURE;
    }
}
