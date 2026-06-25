<?php

use App\ContentEngine\Review\EditCapture;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Enums\EditReason;
use App\Models\Content;
use App\Models\ContentEdit;
use App\Models\Silo;

it('records a correction with full coordinates and the reason tag', function () {
    $content = Content::factory()->page()->create();
    $silo = Silo::factory()->create(['site_id' => $content->site_id]);
    $content->update(['silo_id' => $silo->id]);

    $edit = (new EditCapture)->record($content, 'slot:hero_problem', 'old copy', 'new copy', EditReason::OffBase, 'op-1');

    expect($edit)->not->toBeNull()
        ->and($edit->site_id)->toBe($content->site_id)
        ->and($edit->content_id)->toBe($content->id)
        ->and($edit->silo_id)->toBe($silo->id)
        ->and($edit->user_id)->toBe('op-1')
        ->and($edit->field)->toBe('slot:hero_problem')
        ->and($edit->reason)->toBe(EditReason::OffBase)
        ->and($edit->original)->toBe('old copy')
        ->and($edit->edited)->toBe('new copy');
});

it('skips an unchanged field — an edit that changed nothing is not a signal', function () {
    $content = Content::factory()->page()->create();

    expect((new EditCapture)->record($content, 'body', 'same', ' same ', EditReason::Preference))->toBeNull();
    expect(ContentEdit::query()->count())->toBe(0);
});

it('captureDiff records only the fields that actually changed', function () {
    $content = Content::factory()->page()->create();

    $edits = (new EditCapture)->captureDiff(
        $content,
        ['slot:a' => 'x', 'slot:b' => 'y'],
        ['slot:a' => 'x', 'slot:b' => 'Y changed'],
        EditReason::OffBrand,
    );

    expect($edits)->toHaveCount(1)
        ->and($edits[0]->field)->toBe('slot:b')
        ->and($edits[0]->reason)->toBe(EditReason::OffBrand);
});

it('saveEdits captures the ORIGINAL before overwriting it, tagged with the reason', function () {
    $content = Content::factory()->page()->create([
        'status' => ContentStatus::NeedsReview,
        'slot_payload' => ['hero_problem' => 'GENERATED'],
        'meta' => ['seo' => ['title' => 'Old title']],
    ]);

    app(ReviewActions::class)->saveEdits(
        $content,
        ['slot_payload' => ['hero_problem' => 'CORRECTED'], 'seo' => ['title' => 'New title']],
        EditReason::OffBase,
        'op-2',
    );

    $bySlot = ContentEdit::query()->where('content_id', $content->id)->get()->keyBy('field');
    expect($bySlot['slot:hero_problem']->original)->toBe('GENERATED')
        ->and($bySlot['slot:hero_problem']->edited)->toBe('CORRECTED')
        ->and($bySlot['seo:title']->original)->toBe('Old title')
        ->and($bySlot['seo:title']->edited)->toBe('New title')
        ->and($bySlot->every(fn (ContentEdit $e) => $e->reason === EditReason::OffBase))->toBeTrue();

    // the edit is also persisted
    expect($content->fresh()->slot_payload['hero_problem'])->toBe('CORRECTED');
});

it('saveEdits without a reason persists the edit but captures nothing (back-compat)', function () {
    $content = Content::factory()->page()->create(['slot_payload' => ['hero_problem' => 'OLD']]);

    app(ReviewActions::class)->saveEdits($content, ['slot_payload' => ['hero_problem' => 'NEW']]);

    expect(ContentEdit::query()->count())->toBe(0)
        ->and($content->fresh()->slot_payload['hero_problem'])->toBe('NEW');
});
