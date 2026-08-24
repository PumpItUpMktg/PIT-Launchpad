<?php

namespace App\Console\Commands;

use App\Geo\GeoAnswerJudge;
use App\Geo\GeoVisibilityAudit;
use App\Integrations\AiSearch\AiEngineProvider;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Smoke-check the GEO feature end to end without logging into the panel — the fast way to confirm a deploy
 * is healthy (migrations ran, the operator screen's read query works, the container bindings resolve).
 * Exits non-zero if any hard check fails, so it can gate a deploy or be eyeballed in the Cloud console.
 * The engine's configured/enabled state is reported for information (a missing API key is fine — the audit
 * just no-ops).
 */
class GeoDoctorCommand extends Command
{
    protected $signature = 'sandhog:geo-doctor';

    protected $description = 'Smoke-check the GEO feature (tables, screen read query, bindings, engine config).';

    public function handle(): int
    {
        $hard = [
            'geo_prompts table exists' => fn (): bool => Schema::hasTable('geo_prompts'),
            'geo_snapshots table exists' => fn (): bool => Schema::hasTable('geo_snapshots'),
            // The exact read the operator screen performs: prompts + their latest snapshot (joins geo_snapshots).
            'GEO screen read query runs (prompts + latest result)' => function (): bool {
                GeoPrompt::withoutGlobalScopes()->with('latestSnapshot')->limit(1)->get();

                return true;
            },
            'geo_snapshots is queryable' => function (): bool {
                GeoSnapshot::withoutGlobalScopes()->count();

                return true;
            },
            // Resolving each from the container exercises its binding — a broken wire throws and is caught below.
            'AiEngineProvider resolves' => function (): bool {
                app(AiEngineProvider::class);

                return true;
            },
            'GeoAnswerJudge resolves' => function (): bool {
                app(GeoAnswerJudge::class);

                return true;
            },
            'GeoVisibilityAudit resolves' => function (): bool {
                app(GeoVisibilityAudit::class);

                return true;
            },
        ];

        $rows = [];
        $failed = false;
        foreach ($hard as $label => $probe) {
            [$ok, $detail] = $this->probe($probe);
            $failed = $failed || ! $ok;
            $rows[] = [$ok ? '✓' : '✗', $label, $detail];
        }

        // Informational: engine identity + whether it's configured, and the prompt/snapshot counts.
        $engine = app(AiEngineProvider::class);
        $rows[] = ['·', 'AI engine', $engine->key().' — '.($engine->enabled() ? 'configured' : 'not configured (audit no-ops)')];
        $rows[] = ['·', 'GEO model', (string) config('services.anthropic.geo_model', '—')];
        $rows[] = ['·', 'Prompts / snapshots', $this->countsLine()];

        $this->table(['', 'Check', 'Detail'], $rows);

        if ($failed) {
            $this->error('GEO smoke check FAILED — the screen would error. Most likely the migrations have not run: deploy with `php artisan migrate --force`.');

            return self::FAILURE;
        }

        $this->info('GEO smoke check passed — the operator screen is safe to open.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): bool  $fn
     * @return array{0: bool, 1: string}
     */
    private function probe(callable $fn): array
    {
        try {
            return [(bool) $fn(), 'ok'];
        } catch (Throwable $e) {
            return [false, mb_substr($e->getMessage(), 0, 120)];
        }
    }

    private function countsLine(): string
    {
        try {
            return GeoPrompt::withoutGlobalScopes()->count().' prompts, '.GeoSnapshot::withoutGlobalScopes()->count().' snapshots';
        } catch (Throwable) {
            return '—';
        }
    }
}
