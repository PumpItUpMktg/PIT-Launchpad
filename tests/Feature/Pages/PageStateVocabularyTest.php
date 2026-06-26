<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Site;
use App\Models\VoiceProfile;
use App\Pages\Audience;
use App\Pages\PageState;
use App\Pages\PageStatePresenter;
use Tests\Support\PageFixture;

function presenter(): PageStatePresenter
{
    return app(PageStatePresenter::class);
}

it('keeps the client line identical across audiences for every state (sacred)', function () {
    foreach (PageState::cases() as $state) {
        expect($state->clientLine())->toBe($state->clientLine()); // stable
        // the line is the same string the client screen will read — it takes no audience
        expect($state->clientLine())->toBeString()->not->toBe('');
    }

    // the exact locked lines
    expect(PageState::ReadyToReview->clientLine())->toBe('Ready to review')
        ->and(PageState::Writing->clientLine())->toBe('Writing now')
        ->and(PageState::Live->clientLine())->toBe('Live on your site')
        ->and(PageState::HeldComposer->clientLine())->toBe("We're still preparing this page")
        ->and(PageState::HeldGrounding->clientLine())->toBe("We're still preparing this page");
});

it('flips whose-move by audience ONLY for held and failed states', function () {
    foreach (PageState::cases() as $state) {
        $op = $state->whoseMove(Audience::Operator);
        $client = $state->whoseMove(Audience::Client);

        if ($state->isHeld() || $state === PageState::Failed) {
            expect($op)->not->toBe($client)               // flips
                ->and($client)->toStartWith('Nothing needed');
        } else {
            expect($op)->toBe($client);                   // identical everywhere else
        }
    }
});

it('reserves the operator "Your move" camp for states with an on-screen action; held is pending-a-build', function () {
    // Held = blocked on UNBUILT features → "Not available yet", never "Your move" (no button exists).
    expect(PageState::HeldComposer->whoseMove(Audience::Operator))->toBe('Not available yet — pending the composer build.')
        ->and(PageState::HeldGrounding->whoseMove(Audience::Operator))->toBe('Not available yet — pending Territory→Market.')
        ->and(PageState::HeldComposer->whoseMove(Audience::Operator))->not->toContain('Your move')
        ->and(PageState::HeldGrounding->whoseMove(Audience::Operator))->not->toContain('Your move');

    // Held-intake points to the capture surface (a missing input, not unbuilt code) — neither
    // "Your move" (no on-screen button) nor "Not available yet".
    expect(PageState::HeldIntake->whoseMove(Audience::Operator))->toBe('Needs brand intake — capture it in setup.')
        ->and(PageState::HeldIntake->whoseMove(Audience::Operator))->not->toContain('Your move')
        ->and(PageState::HeldIntake->clientLine())->toBe("We're still preparing this page");

    // The states that DO have an on-screen action keep "Your move" (failed → the retry button).
    expect(PageState::ReadyToGenerate->whoseMove(Audience::Operator))->toStartWith('Your move')
        ->and(PageState::ReadyToReview->whoseMove(Audience::Operator))->toStartWith('Your move')
        ->and(PageState::Approved->whoseMove(Audience::Operator))->toStartWith('Your move')
        ->and(PageState::Failed->whoseMove(Audience::Operator))->toStartWith('Your move');
});

it('resolves a kit-bound, grounded, undrafted page to ready-to-generate', function () {
    $page = PageFixture::intakePage(); // service page, kit + a service, empty slot_payload

    expect(presenter()->resolve($page->fresh()))->toBe(PageState::ReadyToGenerate);
});

it('resolves the drafted lifecycle states', function () {
    $base = PageFixture::intakePage();
    $kit = $base->wireframe_kit_id;
    $site = $base->site;

    $review = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => $kit, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::NeedsReview]);
    $approved = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => $kit, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::Approved]);
    $live = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => $kit, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::Published]);
    $publishing = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => $kit, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::Publishing]);
    $generating = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => $kit, 'slot_payload' => [], 'meta' => ['generating_at' => now()->toIso8601String()]]);

    expect(presenter()->resolve($review))->toBe(PageState::ReadyToReview)
        ->and(presenter()->resolve($approved))->toBe(PageState::Approved)
        ->and(presenter()->resolve($live))->toBe(PageState::Live)
        ->and(presenter()->resolve($publishing))->toBe(PageState::Publishing)
        ->and(presenter()->resolve($generating))->toBe(PageState::Writing);
});

it('resolves held-composer (no kit) and held-grounding (kit, no entities)', function () {
    $noKit = Content::factory()->page()->create(['wireframe_kit_id' => null, 'slot_payload' => []]);

    $base = PageFixture::intakePage();
    $ungrounded = Content::factory()->page()->create([
        'site_id' => Site::factory()->create()->id, // no services
        'wireframe_kit_id' => $base->wireframe_kit_id,
        'page_type' => PageType::Service,
        'slot_payload' => [],
    ]);

    expect(presenter()->resolve($noKit))->toBe(PageState::HeldComposer)
        ->and(presenter()->resolve($ungrounded))->toBe(PageState::HeldGrounding);
});

it('resolves a retryable generate failure and a terminal publish failure to failed', function () {
    $base = PageFixture::intakePage();

    $genFailed = PageFixture::intakePage(['meta' => ['draft_error' => 'budget exhausted']]); // kit+grounded, no draft, error
    $pubFailed = Content::factory()->page()->create([
        'site_id' => $base->site_id, 'wireframe_kit_id' => $base->wireframe_kit_id,
        'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::PublishFailed, 'last_publish_error' => 'WP 500',
    ]);

    expect(presenter()->resolve($genFailed->fresh()))->toBe(PageState::Failed)
        ->and(presenter()->resolve($pubFailed))->toBe(PageState::Failed);
});

it('carries voice provenance in the tail and flags staleness when a newer voice is active', function () {
    $site = Site::factory()->create();
    VoiceProfile::factory()->create(['site_id' => $site->id, 'version' => 1, 'status' => 'archived']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 2]);

    // a review-ready draft produced under voice v1, while v2 is now active → stale
    $stale = Content::factory()->page()->create([
        'site_id' => $site->id, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::NeedsReview,
        'voice_profile_version' => 1,
    ]);
    // one produced under the current v2 → not stale
    $fresh = Content::factory()->page()->create([
        'site_id' => $site->id, 'slot_payload' => ['h' => 'x'], 'status' => ContentStatus::NeedsReview,
        'voice_profile_version' => 2,
    ]);

    expect(presenter()->present($stale, Audience::Operator)->operatorTail)->toContain('voice v1 (current v2 — regenerate)')
        ->and(presenter()->present($fresh, Audience::Operator)->operatorTail)->toContain('voice v2')
        ->and(presenter()->present($fresh, Audience::Operator)->operatorTail)->not->toContain('regenerate');
});

it('appends the operator tail but never to the client', function () {
    $page = Content::factory()->page()->create(['wireframe_kit_id' => null, 'slot_payload' => []]); // held-composer

    $operator = presenter()->present($page, Audience::Operator);
    $client = presenter()->present($page, Audience::Client);

    expect($operator->clientLine)->toBe($client->clientLine)        // sacred line shared
        ->and($operator->operatorTail)->toBe('composer pending')    // tail names the real blocker
        ->and($operator->whoseMove)->toBe('Not available yet — pending the composer build.')
        ->and($client->operatorTail)->toBeNull()                    // client never sees the tail
        ->and($client->whoseMove)->toBe("Nothing needed — we're getting this ready.");
});
