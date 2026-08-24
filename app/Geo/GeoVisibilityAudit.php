<?php

namespace App\Geo;

use App\Integrations\AiSearch\AiEngineProvider;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Runs a site's active GEO prompts through the AI engine, judges each answer, and writes the durable
 * {@see GeoSnapshot} readings the operator GEO board reads. Budget-bounded + freshness-cached like the
 * vitals / index audits (each prompt is one web-search answer + a Haiku judge, so a run measures until the
 * budget is spent and skips prompts already checked recently). Clean no-op when the engine is disabled.
 */
class GeoVisibilityAudit
{
    public function __construct(
        private readonly AiEngineProvider $engine,
        private readonly GeoAnswerJudge $judge,
    ) {}

    /**
     * @return array{enabled: bool, total: int, measured: int, skipped_fresh: int, deferred: int}
     */
    public function audit(Site $site, ?float $budgetSeconds = null, ?int $freshnessDays = null): array
    {
        $total = 0;
        $measured = 0;
        $skippedFresh = 0;
        $deferred = 0;

        if (! $this->engine->enabled()) {
            return ['enabled' => false, 'total' => 0, 'measured' => 0, 'skipped_fresh' => 0, 'deferred' => 0];
        }

        $budget = $budgetSeconds ?? (float) config('launchpad.geo.budget_seconds', 240);
        $freshness = $freshnessDays ?? (int) config('launchpad.geo.freshness_days', 6);
        $deadline = microtime(true) + $budget;
        $freshBefore = Carbon::now()->subDays($freshness);
        $engineKey = $this->engine->key();

        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('active', true)->get();

        $brand = (string) $site->brand_name;
        $domain = $site->domain_url;

        foreach ($prompts as $prompt) {
            $total++;

            $latest = GeoSnapshot::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('geo_prompt_id', $prompt->id)->where('engine', $engineKey)
                ->latest('checked_at')->first();
            if ($latest !== null && $latest->checked_at->greaterThan($freshBefore)) {
                $skippedFresh++;

                continue;
            }

            if (microtime(true) >= $deadline) {
                $deferred++;

                continue;
            }

            $answer = $this->engine->ask($prompt->prompt);
            if ($answer === null) {
                continue;   // engine error — keep prior readings, don't write a blank
            }

            $verdict = $this->judge->judge($brand, $domain, $prompt->prompt, $answer);

            GeoSnapshot::create([
                'site_id' => $site->id,
                'geo_prompt_id' => $prompt->id,
                'engine' => $engineKey,
                'cited' => $verdict->cited,
                'position' => $verdict->position,
                'sentiment' => $verdict->sentiment,
                'competitors' => $verdict->competitors,
                'answer_excerpt' => Str::limit($answer->text, 500),
                'checked_at' => Carbon::now(),
            ]);
            $measured++;
        }

        return ['enabled' => true, 'total' => $total, 'measured' => $measured, 'skipped_fresh' => $skippedFresh, 'deferred' => $deferred];
    }
}
