<?php

use App\ColdOutreach\ColdPipeline;
use App\Enums\ColdContactStage;
use App\Enums\ConnectionProvider;
use App\Integrations\ColdOutreach\OutreachCredentials;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function pipelineSite(array $config = []): Site
{
    $site = Site::factory()->create();
    ColdOutreachConfig::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id,
        'enabled' => true,
        'instantly_campaign_id' => 'camp-1',
        'brevo_list_id' => 7,
    ], $config));
    $creds = app(OutreachCredentials::class);
    $creds->store($site, ConnectionProvider::Bird, ['api_key' => 'bk_eu1_site']);
    $creds->store($site, ConnectionProvider::Instantly, ['api_key' => 'inst-site']);
    $creds->store($site, ConnectionProvider::Brevo, ['api_key' => 'brevo-site']);

    return $site;
}

function coldContact(Site $site, string $email, ColdContactStage $stage, array $attrs = []): ColdContact
{
    return ColdContact::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id,
        'email' => $email,
        'email_key' => ColdContact::keyFor($email),
        'first_name' => 'Ann',
        'stage' => $stage,
    ], $attrs));
}

it('verifies imported contacts with one billed-once Bird batch on the key\'s region', function () {
    $site = pipelineSite();
    $good = coldContact($site, 'good@x.test', ColdContactStage::Imported);
    $role = coldContact($site, 'info@x.test', ColdContactStage::Imported);
    $bad = coldContact($site, 'gone@x.test', ColdContactStage::Imported);

    Http::fake(['eu1.platform.bird.com/v1/lookup/email/batch' => Http::response(['data' => [
        ['email' => 'good@x.test', 'valid' => true, 'result' => 'valid', 'delivery_confidence' => 97, 'flags' => []],
        ['email' => 'info@x.test', 'valid' => true, 'result' => 'risky', 'delivery_confidence' => 60, 'flags' => ['role']],
        ['email' => 'gone@x.test', 'valid' => false, 'result' => 'undeliverable', 'delivery_confidence' => 0, 'flags' => [], 'reason' => 'invalid_recipient'],
    ]])]);

    expect(app(ColdPipeline::class)->verify($site))->toBe(3);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bk_eu1_site')
        && str_starts_with($r->header('Idempotency-Key')[0], 'cold-verify/'.$site->id.'/')
        && $r['emails'] === ['good@x.test', 'info@x.test', 'gone@x.test']);
    expect($good->fresh()->stage)->toBe(ColdContactStage::Verified)
        ->and($role->fresh()->stage)->toBe(ColdContactStage::Risky)
        ->and($role->fresh()->verify_flags)->toBe(['role'])
        ->and($bad->fresh()->stage)->toBe(ColdContactStage::Invalid)
        ->and($bad->fresh()->verify_reason)->toBe('invalid_recipient');
});

it('enrolls verified contacts in the site\'s Instantly campaign without double-sequencing', function () {
    $site = pipelineSite();
    $c = coldContact($site, 'good@x.test', ColdContactStage::Verified, ['company' => 'Acme', 'custom' => ['industry' => 'HVAC', 'nested' => ['x']]]);

    Http::fake(['api.instantly.ai/api/v2/leads' => Http::response(['id' => 'lead-9', 'email' => 'good@x.test', 'status' => 1])]);

    expect(app(ColdPipeline::class)->enroll($site))->toBe(1);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer inst-site')
        && $r['campaign'] === 'camp-1' && $r['email'] === 'good@x.test' && $r['company_name'] === 'Acme'
        && $r['skip_if_in_workspace'] === true && $r['custom_variables'] === ['industry' => 'HVAC']);
    $fresh = $c->fresh();
    expect($fresh->stage)->toBe(ColdContactStage::Warming)
        ->and($fresh->instantly_lead_id)->toBe('lead-9')
        ->and($fresh->instantly_campaign_id)->toBe('camp-1');
});

it('stops enrolling — and leaves contacts untouched — when no campaign is set', function () {
    $site = pipelineSite(['instantly_campaign_id' => null]);
    $c = coldContact($site, 'good@x.test', ColdContactStage::Verified);
    Http::fake();

    $report = app(ColdPipeline::class)->advance($site);

    expect($report['enroll']['error'])->toContain('No Instantly campaign')
        ->and($c->fresh()->stage)->toBe(ColdContactStage::Verified);
    Http::assertNothingSent();
});

it('records a per-contact Instantly failure and carries on', function () {
    $site = pipelineSite();
    $a = coldContact($site, 'a@x.test', ColdContactStage::Verified);
    $b = coldContact($site, 'b@x.test', ColdContactStage::Verified);
    Http::fake(['api.instantly.ai/api/v2/leads' => Http::sequence()
        ->push(['message' => 'bad lead'], 400)
        ->push(['id' => 'lead-b'], 200)]);

    expect(app(ColdPipeline::class)->enroll($site))->toBe(1);
    expect($a->fresh()->stage)->toBe(ColdContactStage::Verified)
        ->and($a->fresh()->last_error)->toContain('bad lead')
        ->and($b->fresh()->stage)->toBe(ColdContactStage::Warming);
});

it('syncs engagement from Instantly and moves contacts to warm or out', function () {
    $site = pipelineSite();
    $replied = coldContact($site, 'Replied@x.test', ColdContactStage::Warming, ['instantly_campaign_id' => 'camp-1']);
    $quiet = coldContact($site, 'quiet@x.test', ColdContactStage::Warming, ['instantly_campaign_id' => 'camp-1']);
    $bounced = coldContact($site, 'bounce@x.test', ColdContactStage::Warming, ['instantly_campaign_id' => 'camp-1']);

    Http::fake(['api.instantly.ai/api/v2/leads/list' => Http::sequence()
        ->push(['items' => [
            ['id' => 'l1', 'email' => 'replied@x.test', 'status' => 1, 'email_open_count' => 3, 'email_reply_count' => 1],
            ['id' => 'l2', 'email' => 'quiet@x.test', 'status' => 1, 'email_open_count' => 5, 'email_reply_count' => 0],
        ], 'next_starting_after' => 'l2'])
        ->push(['items' => [
            ['id' => 'l3', 'email' => 'bounce@x.test', 'status' => -1],
            ['id' => 'l4', 'email' => 'stranger@x.test', 'status' => 1, 'email_reply_count' => 4],
        ]])]);

    expect(app(ColdPipeline::class)->sync($site))->toBe(3);

    expect($replied->fresh()->stage)->toBe(ColdContactStage::Warm)
        ->and($replied->fresh()->warm_reason)->toBe('replied')
        ->and($quiet->fresh()->stage)->toBe(ColdContactStage::Warming)
        ->and($quiet->fresh()->opens)->toBe(5)
        ->and($bounced->fresh()->stage)->toBe(ColdContactStage::Disqualified);
    Http::assertSent(fn (Request $r) => $r['starting_after'] === 'l2');
});

it('promotes warm contacts into the site\'s Brevo list', function () {
    $site = pipelineSite();
    $warm = coldContact($site, 'warm@x.test', ColdContactStage::Warm, ['last_name' => 'Lee']);
    Http::fake(['api.brevo.com/v3/contacts' => Http::response(['id' => 321], 201)]);

    expect(app(ColdPipeline::class)->promote($site))->toBe(1);

    Http::assertSent(fn (Request $r) => $r->hasHeader('api-key', 'brevo-site')
        && $r['listIds'] === [7] && $r['updateEnabled'] === true
        && $r['attributes'] === ['FIRSTNAME' => 'Ann', 'LASTNAME' => 'Lee']);
    expect($warm->fresh()->stage)->toBe(ColdContactStage::Promoted)
        ->and($warm->fresh()->brevo_contact_id)->toBe('321');
});

it('treats Brevo\'s 204 (contact existed) as promoted', function () {
    $site = pipelineSite();
    $warm = coldContact($site, 'warm@x.test', ColdContactStage::Warm);
    Http::fake(['api.brevo.com/v3/contacts' => Http::response(null, 204)]);

    app(ColdPipeline::class)->promote($site);

    expect($warm->fresh()->stage)->toBe(ColdContactStage::Promoted);
});

it('holds warm contacts when auto-promote is off', function () {
    $site = pipelineSite(['auto_promote' => false]);
    $warm = coldContact($site, 'warm@x.test', ColdContactStage::Warm);
    Http::fake();

    expect(app(ColdPipeline::class)->promote($site))->toBe(0);
    expect($warm->fresh()->stage)->toBe(ColdContactStage::Warm);
    Http::assertNothingSent();
});

it('falls back to the platform key when a site has none, and refuses when neither exists', function () {
    $site = Site::factory()->create();
    ColdOutreachConfig::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id]);
    coldContact($site, 'a@x.test', ColdContactStage::Imported);

    $report = app(ColdPipeline::class)->advance($site);
    expect($report['verify']['error'])->toContain('Bird API key is not configured');

    config(['services.bird.api_key' => 'bk_us1_platform']);
    Http::fake(['us1.platform.bird.com/*' => Http::response(['data' => [['result' => 'valid', 'delivery_confidence' => 99, 'flags' => []]]])]);

    expect(app(ColdPipeline::class)->verify($site))->toBe(1);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bk_us1_platform'));
});

it('stores site keys as clean connections that never block the launch gate', function () {
    $site = pipelineSite();

    $conn = Connection::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('provider', 'brevo')->sole();

    expect($conn->compromised)->toBeFalse()->and($conn->needsRotation())->toBeFalse()
        ->and($conn->credentials)->toBe(['api_key' => 'brevo-site']);
});

it('advances every enabled site from the command', function () {
    $on = pipelineSite();
    $off = pipelineSite(['enabled' => false]);
    $a = coldContact($on, 'a@x.test', ColdContactStage::Imported);
    $b = coldContact($off, 'b@x.test', ColdContactStage::Imported);
    Http::fake(['*/v1/lookup/email/batch' => Http::response(['data' => [['result' => 'valid', 'delivery_confidence' => 99, 'flags' => []]]]),
        'api.instantly.ai/*' => Http::response(['id' => 'l', 'items' => []])]);

    $this->artisan('launchpad:cold-advance')->assertSuccessful();

    expect($a->fresh()->stage)->toBe(ColdContactStage::Warming)
        ->and($b->fresh()->stage)->toBe(ColdContactStage::Imported);
});
