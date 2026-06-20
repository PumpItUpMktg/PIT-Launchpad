<?php

use App\Enums\SetupStep;
use App\Guided\StepGate;
use App\Models\SetupState;
use App\Models\Site;

beforeEach(function () {
    $this->gate = app(StepGate::class);
});

test('a fresh state unlocks only step 1', function () {
    $state = SetupState::factory()->create();

    expect($this->gate->isUnlocked($state, SetupStep::Business))->toBeTrue()
        ->and($this->gate->isUnlocked($state, SetupStep::Territory))->toBeFalse()
        ->and($this->gate->isUnlocked($state, SetupStep::Structure))->toBeFalse()
        ->and($this->gate->furthestUnlocked($state))->toBe(SetupStep::Business);
});

test('completing a step sets its gate, advances current_step, and unlocks the next', function () {
    $state = SetupState::factory()->create();

    $this->gate->complete($state, SetupStep::Business);

    expect($state->services_done)->toBeTrue()
        ->and($state->current_step)->toBe(2)
        ->and($this->gate->isUnlocked($state, SetupStep::Territory))->toBeTrue()
        ->and($this->gate->isUnlocked($state, SetupStep::Structure))->toBeFalse();
});

test('resolve sends a locked target back to the current step, but honors an unlocked one', function () {
    $state = SetupState::factory()->create(); // fresh: on step 1

    expect($this->gate->resolve($state, SetupStep::Structure))->toBe(SetupStep::Business); // locked → current

    $this->gate->complete($state, SetupStep::Business);

    expect($this->gate->resolve($state, SetupStep::Territory))->toBe(SetupStep::Territory); // now unlocked
});

test('completing the whole chain unlocks Grow', function () {
    $state = SetupState::factory()->create();

    foreach (SetupStep::setupSteps() as $step) {
        $this->gate->complete($state, $step);
    }

    expect($this->gate->isUnlocked($state, SetupStep::Grow))->toBeTrue()
        ->and($this->gate->furthestUnlocked($state))->toBe(SetupStep::Grow)
        ->and($state->approved)->toBeTrue();
});

test('complete never moves current_step backward', function () {
    $state = SetupState::factory()->create(['current_step' => 3]);

    $this->gate->complete($state, SetupStep::Business);

    expect($state->services_done)->toBeTrue()
        ->and($state->current_step)->toBe(3); // not pulled back to 2
});

test('state() is created once per site', function () {
    $site = Site::factory()->create();

    $a = $this->gate->state($site);
    $b = $this->gate->state($site);

    expect($a->id)->toBe($b->id)
        ->and(SetupState::query()->where('site_id', $site->id)->count())->toBe(1);
});
