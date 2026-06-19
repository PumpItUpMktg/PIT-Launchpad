<?php

use App\Client\PlanApproval;
use App\Models\SiloBlueprint;
use Tests\Support\ClientHarness;

test('a client signs off on their own plan — who and when are recorded', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    expect(app(PlanApproval::class)->approve($site, $client))->toBeTrue();

    $bp->refresh();
    expect($bp->isClientApproved())->toBeTrue()
        ->and($bp->client_approved_by)->toBe($client->id)
        ->and($bp->client_approved_at)->not->toBeNull();
});

test('a client cannot approve a plan for a Site outside their Account', function () {
    ['user' => $client] = ClientHarness::make();
    ['site' => $foreign] = ClientHarness::make();
    $bp = SiloBlueprint::factory()->create(['site_id' => $foreign->id]);

    expect(app(PlanApproval::class)->approve($foreign, $client))->toBeFalse()
        ->and($bp->refresh()->isClientApproved())->toBeFalse();
});

test('approving fails cleanly when the site has no blueprint yet', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();

    expect(app(PlanApproval::class)->approve($site, $client))->toBeFalse();
});

test('status reflects the recorded sign-off', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    SiloBlueprint::factory()->create(['site_id' => $site->id]);

    expect(app(PlanApproval::class)->status($site)['approved'])->toBeFalse();

    app(PlanApproval::class)->approve($site, $client);

    $status = app(PlanApproval::class)->status($site);
    expect($status['approved'])->toBeTrue()
        ->and($status['at'])->not->toBeNull();
});
