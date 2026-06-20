<?php

namespace App\Guided;

use App\Enums\SetupStep;
use App\Models\SetupState;
use App\Models\Site;

/**
 * The guided-flow gatekeeper: resolves a site's {@see SetupState}, decides which steps are
 * unlocked (a step opens only once the prior step's completion gate is set), and advances the
 * flow when a step completes. Requesting a step beyond the furthest-unlocked is redirected back
 * to the current step — so the pipeline's dependency order is enforced structurally, not by
 * convention.
 */
class StepGate
{
    /** Load (or create) the site's setup state. */
    public function state(Site $site): SetupState
    {
        return SetupState::query()->firstOrCreate(['site_id' => $site->id]);
    }

    /** A step is unlocked when its prerequisite gate is set (Business is always open). */
    public function isUnlocked(SetupState $state, SetupStep $step): bool
    {
        $prerequisite = $step->prerequisiteFlag();

        return $prerequisite === null || (bool) $state->{$prerequisite};
    }

    /** The furthest step the operator may currently reach. */
    public function furthestUnlocked(SetupState $state): SetupStep
    {
        $furthest = SetupStep::Business;
        foreach ([...SetupStep::setupSteps(), SetupStep::Grow] as $step) {
            if ($this->isUnlocked($state, $step)) {
                $furthest = $step;
            }
        }

        return $furthest;
    }

    /**
     * Where a request for `$target` should land: the target itself if unlocked, otherwise the
     * step the operator is on (never deeper than the furthest unlocked).
     */
    public function resolve(SetupState $state, SetupStep $target): SetupStep
    {
        if ($this->isUnlocked($state, $target)) {
            return $target;
        }

        $current = $state->step();

        return $this->isUnlocked($state, $current) ? $current : $this->furthestUnlocked($state);
    }

    /**
     * Mark a step complete and advance: set its completion gate and move `current_step` to the
     * next step (clamped to Grow). Idempotent — re-completing a step never moves backward.
     */
    public function complete(SetupState $state, SetupStep $step): SetupState
    {
        $attrs = [];
        $flag = $step->completionFlag();
        if ($flag !== null) {
            $attrs[$flag] = true;
        }

        $next = SetupStep::tryFrom($step->value + 1) ?? SetupStep::Grow;
        if ($next->value > $state->current_step) {
            $attrs['current_step'] = $next->value;
        }

        $state->update($attrs);

        return $state;
    }
}
