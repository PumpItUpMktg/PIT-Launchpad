<?php

use App\Enums\ColdContactStage;
use App\Enums\ConnectionProvider;
use App\Integrations\ColdOutreach\OutreachCredentials;
use App\Jobs\AdvanceColdPipeline;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

function webhookSite(string $secret = 'tok-a'): Site
{
    $site = Site::factory()->create();
    ColdOutreachConfig::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'enabled' => true, 'brevo_list_id' => 1]);
    app(OutreachCredentials::class)->store($site, ConnectionProvider::Instantly, ['webhook_secret' => $secret]);

    return $site;
}

function warmingContact(Site $site, string $email = 'lead@x.test'): ColdContact
{
    return ColdContact::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'email' => $email, 'email_key' => ColdContact::keyFor($email), 'stage' => ColdContactStage::Warming,
    ]);
}

function hook(Site $site, array $payload, string $token = 'tok-a')
{
    return test()->postJson(route('webhooks.instantly', ['site' => $site->id]), $payload + [
        'timestamp' => now()->toIso8601String(), 'workspace' => 'w', 'campaign_id' => 'camp-1', 'campaign_name' => 'Warm',
    ], ['X-Launchpad-Token' => $token]);
}

it('rejects a webhook without the site token', function () {
    $site = webhookSite();
    $contact = warmingContact($site);

    hook($site, ['event_type' => 'reply_received', 'lead_email' => 'lead@x.test'], 'wrong')->assertStatus(401);

    expect($contact->fresh()->replies)->toBe(0);
});

it('warms a contact on reply and queues its promotion to Brevo', function () {
    Queue::fake();
    $site = webhookSite();
    $contact = warmingContact($site);

    hook($site, ['event_type' => 'email_opened', 'lead_email' => 'LEAD@x.test'])->assertOk()->assertJson(['stage' => 'warming']);
    hook($site, ['event_type' => 'reply_received', 'lead_email' => 'lead@x.test'])->assertOk()->assertJson(['stage' => 'warm']);

    $fresh = $contact->fresh();
    expect($fresh->opens)->toBe(1)->and($fresh->replies)->toBe(1)->and($fresh->warm_reason)->toBe('replied');
    Queue::assertPushed(AdvanceColdPipeline::class, fn ($job) => $job->siteId === $site->id);
});

it('takes an unsubscribe over an earlier reply', function () {
    Queue::fake();
    $site = webhookSite();
    $contact = warmingContact($site);

    hook($site, ['event_type' => 'reply_received', 'lead_email' => 'lead@x.test']);
    hook($site, ['event_type' => 'lead_unsubscribed', 'lead_email' => 'lead@x.test']);

    expect($contact->fresh()->stage)->toBe(ColdContactStage::Disqualified)
        ->and($contact->fresh()->disqualified_reason)->toBe('unsubscribed');
});

it('never lets one tenant\'s token touch another tenant\'s contact', function () {
    $a = webhookSite('tok-a');
    $b = webhookSite('tok-b');
    $bContact = warmingContact($b);

    // Site A's token on site A's URL, naming site B's lead: A has no such contact.
    hook($a, ['event_type' => 'reply_received', 'lead_email' => 'lead@x.test'], 'tok-a')->assertOk()->assertJson(['ignored' => 'unknown lead']);
    // Site A's token on site B's URL: refused.
    hook($b, ['event_type' => 'reply_received', 'lead_email' => 'lead@x.test'], 'tok-a')->assertStatus(401);

    expect($bContact->fresh()->replies)->toBe(0);
});

it('refuses every call for a site that never generated a token', function () {
    $site = Site::factory()->create();

    hook($site, ['event_type' => 'reply_received', 'lead_email' => 'x@x.test'], '')->assertStatus(401);
});
