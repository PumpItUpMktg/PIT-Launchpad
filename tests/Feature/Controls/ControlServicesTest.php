<?php

use App\Enums\VoiceStatus;
use App\Models\Content;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Models\Source;
use App\Models\VoiceProfile;
use App\Operator\Controls\BudgetControl;
use App\Operator\Controls\CadenceControl;
use App\Operator\Controls\FeedControl;
use App\Operator\Controls\VoiceControl;

test('feed control enables and disables a source', function () {
    $source = Source::factory()->create(['enabled' => true]);

    app(FeedControl::class)->disable($source);
    expect($source->fresh()->enabled)->toBeFalse();

    app(FeedControl::class)->enable($source);
    expect($source->fresh()->enabled)->toBeTrue();
});

test('budget control sets the ceiling and reports read-only usage', function () {
    $site = Site::factory()->create();
    PositionSnapshot::factory()->count(3)->create(['site_id' => $site->id, 'captured_at' => now()]);

    $budget = app(BudgetControl::class);
    $budget->setCeiling($site, 10);

    expect($budget->ceiling($site->fresh()))->toBe(10)
        ->and($budget->usage($site))->toBe(3)
        ->and($budget->remaining($site->fresh()))->toBe(7)
        ->and($budget->overBudget($site->fresh()))->toBeFalse();
});

test('voice control lists versions, the active version, and activates a new one', function () {
    $site = Site::factory()->create();
    $v1 = VoiceProfile::factory()->create(['site_id' => $site->id, 'version' => 1, 'status' => VoiceStatus::Active]);
    $v2 = VoiceProfile::factory()->create(['site_id' => $site->id, 'version' => 2, 'status' => VoiceStatus::Draft]);

    $voice = app(VoiceControl::class);
    expect($voice->versions($site)->pluck('version')->all())->toBe([2, 1])
        ->and($voice->activeVersion($site))->toBe(1);

    $voice->activate($v2);

    expect($v2->fresh()->status)->toBe(VoiceStatus::Active)
        ->and($v1->fresh()->status)->toBe(VoiceStatus::Archived)
        ->and($voice->activeVersion($site->fresh()))->toBe(2);
});

test('voice control reports which version is pinned to recent content', function () {
    $site = Site::factory()->create();
    Content::factory()->count(2)->create(['site_id' => $site->id, 'voice_profile_version' => 3]);
    Content::factory()->create(['site_id' => $site->id, 'voice_profile_version' => 2]);

    $pinned = app(VoiceControl::class)->pinnedVersions($site);
    expect($pinned)->toHaveCount(2)
        ->and($pinned[3])->toBe(2)
        ->and($pinned[2])->toBe(1);
});

test('cadence control exposes the tier degradation order (C dropped first)', function () {
    $tiers = app(CadenceControl::class)->tiers();

    expect(array_column($tiers, 'tier'))->toBe(['c', 'b', 'a']);
});
