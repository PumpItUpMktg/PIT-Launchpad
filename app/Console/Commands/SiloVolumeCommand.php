<?php

namespace App\Console\Commands;

use App\Interview\Volume\VolumeException;
use App\Interview\Volume\VolumeGrounder;
use App\Interview\Volume\VolumeResult;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Phase 3 calibration surface: ground the candidate tree with service-area-localized
 * DataForSEO volume. Resolves the covered metros from the Locations coverage set,
 * pulls batched Google Ads search volume per metro, sums per head keyword, and writes
 * each spoke's volume + per-metro breakdown + the advisory fold/own-page granularity.
 *
 * DataForSEO is PAID — this is the explicit, deliberate trigger; it queries + persists
 * on run (stamping volume_at). The gate is the human eyeball: sane volumes, the right
 * metros resolved, reasonable fold flags.
 *
 *   launchpad:silo-volume {site} [--json]
 */
class SiloVolumeCommand extends Command
{
    protected $signature = 'launchpad:silo-volume
        {site : the Site id}
        {--json : emit the grounded tree as JSON}';

    protected $description = 'Ground the candidate tree with service-area-localized DataForSEO volume (paid; persists).';

    public function handle(VolumeGrounder $grounder): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        try {
            $result = $grounder->ground($site);
        } catch (VolumeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    private function render(VolumeResult $result): void
    {
        $metros = implode(', ', array_map(fn ($m) => $m->name.($m->isFallback ? ' (state)' : ''), $result->metros));
        $this->info('Metros: '.$metros);
        if ($result->skippedMetros !== []) {
            $this->warn('Skipped (no DataForSEO match): '.implode(', ', array_map(fn ($m) => $m->name, $result->skippedMetros)));
        }

        $currentSilo = null;
        foreach ($result->spokes as $spoke) {
            if ($spoke->silo !== $currentSilo) {
                $currentSilo = $spoke->silo;
                $this->newLine();
                $this->info("▌ {$currentSilo}");
            }
            $fold = $spoke->isPillar ? 'pillar' : $spoke->granularity->value;
            $this->line(sprintf('   %6d  %-9s %s', $spoke->volume, $fold, $spoke->name));
        }

        $this->newLine();
        $this->line(sprintf('%d spokes grounded · %d own-page · %d folded · across %d metros — written + stamped.',
            count($result->spokes), $result->ownPageCount(), $result->foldedCount(), count($result->metros)));
    }
}
