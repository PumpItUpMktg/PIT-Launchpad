<?php

namespace App\Geo;

use App\Integrations\AiSearch\AiEngineRegistry;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Runs a site's active GEO prompts through every ENABLED AI engine, judges each answer, and writes the
 * durable {@see GeoSnapshot} readings the operator GEO board reads. Each (prompt × engine) pair is one
 * answer + a Haiku judge, budget-bounded + freshness-cached PER pair (like the vitals / index audits), so
 * a run measures until the budget is spent and skips pairs checked recently. Clean no-op when no engine is
 * configured.
 */
class GeoVisibilityAudit
{
    public function __construct(
        private readonly AiEngineRegistry $registry,
        private readonly GeoAnswerJudge $judge,
    ) {}

    /**
     * @return array{enabled: bool, engines: int, total: int, measured: int, skipped_fresh: int, deferred: int}
     */
    public function audit(Site $site, ?float $budgetSeconds = null, ?int $freshnessDays = null): array
    {
        $total = 0;
        $measured = 0;
        $skippedFresh = 0;
        $deferred = 0;

        $engines = $this->registry->enabled();
        if ($engines === []) {
            return ['enabled' => false, 'engines' => 0, 'total' => 0, 'measured' => 0, 'skipped_fresh' => 0, 'deferred' => 0];
        }

        $budget = $budgetSeconds ?? (float) config('launchpad.geo.budget_seconds', 240);
        $freshness = $freshnessDays ?? (int) config('launchpad.geo.freshness_days', 6);
        $deadline = microtime(true) + $budget;
        $freshBefore = Carbon::now()->subDays($freshness);

        // Operator priority first, then biggest towns (major → small), then oldest — so a budget-bounded run
        // spends its calls on the pinned + highest-value municipalities and the freshness cache advances the
        // rest next run.
        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('active', true)
            ->workOrder()
            ->get();

        $brand = (string) $site->brand_name;
        $domain = $site->domain_url;

        // Mark the tenant "checking" for the in-process indicator; always cleared, even on error.
        $status = app(GeoCheckStatus::class);
        $status->begin((string) $site->id);

        try {
            foreach ($prompts as $prompt) {
                foreach ($engines as $engine) {
                    $total++;
                    $engineKey = $engine->key();

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

                    $answer = $engine->ask($prompt->prompt);
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
            }
        } finally {
            $status->finish((string) $site->id);
        }

        return ['enabled' => true, 'engines' => count($engines), 'total' => $total, 'measured' => $measured, 'skipped_fresh' => $skippedFresh, 'deferred' => $deferred];
    }
}
