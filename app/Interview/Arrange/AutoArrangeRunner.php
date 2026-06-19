<?php

namespace App\Interview\Arrange;

use App\Models\ArrangementFlag;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * The committed auto-arrange run: applies the B→C→A→D→E passes and persists the run's
 * flagged-for-confirm list (replace-on-run, per site) so the prune surface can render
 * accept/dismiss without re-running the embedding passes. The whole run + the flag replace
 * are one transaction. The command's --dry-run path does NOT use this (it rolls back and
 * never persists). Idempotent: a re-run re-applies the stable defaults and rewrites the
 * same flag set (confirmed structure is preserved by the passes themselves).
 */
final class AutoArrangeRunner
{
    public function __construct(private readonly AutoArranger $arranger) {}

    public function run(Site $site): ArrangeResult
    {
        return DB::transaction(function () use ($site): ArrangeResult {
            $result = $this->arranger->arrange($site);
            $this->persistFlags($site, $result);

            return $result;
        });
    }

    private function persistFlags(Site $site, ArrangeResult $result): void
    {
        ArrangementFlag::query()->where('site_id', $site->id)->delete();

        foreach ($result->flags as $flag) {
            ArrangementFlag::query()->create([
                'site_id' => $site->id,
                'spoke_id' => $flag->spokeId,
                'type' => $flag->type,
                'message' => $flag->message,
                'candidates' => $flag->candidates,
                'score' => $flag->candidates[0]['score'] ?? null,
            ]);
        }
    }
}
