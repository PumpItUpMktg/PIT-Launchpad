<?php

namespace App\Console\Commands;

use App\Enums\VoiceStatus;
use App\Interview\Expansion\ExpansionException;
use App\Interview\Expansion\ExpansionPersister;
use App\Interview\Expansion\ExpansionResult;
use App\Interview\Expansion\SiloExpander;
use App\Interview\SiloSeed;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;
use Illuminate\Console\Command;

/**
 * Phase 2 calibration surface: run the headless problem-chain expansion against a
 * site's confirmed seed + voice and print the candidate tree (silos → tagged spokes +
 * head keywords + connection notes + the fringe handoff set). Dry-run by default —
 * eyeball before writing; --persist writes a draft SiloBlueprint the Phase 4 prune
 * later operates on.
 *
 *   launchpad:silo-expand {site} [--json] [--persist]
 */
class SiloExpandCommand extends Command
{
    protected $signature = 'launchpad:silo-expand
        {site : the Site id}
        {--json : emit the raw candidate tree as JSON}
        {--persist : write the tree as a draft SiloBlueprint (default is dry-run)}';

    protected $description = 'Expand a site\'s confirmed seed into the problem-chain candidate tree (dry-run by default).';

    public function handle(SiloExpander $expander, ExpansionPersister $persister): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();

        if ($blueprint === null || ! is_array($blueprint->seed) || ($blueprint->seed['trade'] ?? '') === '') {
            $this->error('No confirmed seed for this site — run the owner interview first.');

            return self::FAILURE;
        }

        try {
            $result = $expander->expand(SiloSeed::fromArray($blueprint->seed), $this->voice($site));
        } catch (ExpansionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($result);
        }

        if ($this->option('persist')) {
            $persister->persist($site, $result);
            $this->newLine();
            $this->info('Persisted draft blueprint: '.count($result->silos).' silos, '
                .$result->spokeCount().' spokes, '.count($result->fringeHandoff).' fringe handoffs.');
        } else {
            $this->newLine();
            $this->comment('Dry run — nothing written. Re-run with --persist to save the draft blueprint.');
        }

        return self::SUCCESS;
    }

    private function render(ExpansionResult $result): void
    {
        foreach ($result->silos as $silo) {
            $this->newLine();
            $kw = $silo->headKeyword !== '' ? " [{$silo->headKeyword}]" : '';
            $this->info("▌ {$silo->name}{$kw}");
            foreach ($silo->spokes as $spoke) {
                $note = $spoke->connectionNote !== null ? "  — {$spoke->connectionNote}" : '';
                $kw = $spoke->headKeyword !== '' ? " ({$spoke->headKeyword})" : '';
                $this->line(sprintf('   %-11s %-7s %s%s%s',
                    $spoke->tag->value, $spoke->pageType->value, $spoke->name, $kw, $note));
            }
        }

        if ($result->fringeHandoff !== []) {
            $this->newLine();
            $this->warn('▌ Fringe handoff (→ Routing layer; no pages built here)');
            foreach ($result->fringeHandoff as $fringe) {
                $brand = $fringe->siblingBrand !== null ? " → {$fringe->siblingBrand}" : '';
                $note = $fringe->connectionNote !== null ? "  — {$fringe->connectionNote}" : '';
                $this->line("   {$fringe->name}{$brand}{$note}");
            }
        }

        $this->newLine();
        $this->line(sprintf('%d silos · %d spokes · %d fringe handoffs',
            count($result->silos), $result->spokeCount(), count($result->fringeHandoff)));
    }

    /**
     * @return array<string, mixed>
     */
    private function voice(Site $site): array
    {
        $profile = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        return $profile === null ? [] : [
            'framing_model' => $profile->framing_model,
            'tone_axes' => $profile->tone_axes,
            'persona' => $profile->persona,
            'language_rules' => $profile->language_rules,
            'audience' => $profile->audience,
            'cta_voice' => $profile->cta_voice,
        ];
    }
}
