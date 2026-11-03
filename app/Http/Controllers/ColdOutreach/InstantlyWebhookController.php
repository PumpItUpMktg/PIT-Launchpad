<?php

namespace App\Http\Controllers\ColdOutreach;

use App\ColdOutreach\EngagementRecorder;
use App\Enums\ColdContactStage;
use App\Integrations\ColdOutreach\OutreachCredentials;
use App\Jobs\AdvanceColdPipeline;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Instantly webhook receiver: `POST /webhooks/instantly/{site}`. Instantly has no payload signing, so
 * the webhook is registered with a custom header `X-Launchpad-Token: <site secret>` (generated on the
 * Cold outreach settings screen) and checked here in constant time. Unknown sites, bad tokens and
 * unknown leads all answer without revealing which one failed.
 */
class InstantlyWebhookController
{
    public const HEADER = 'X-Launchpad-Token';

    public function __invoke(Request $request, string $site, OutreachCredentials $credentials, EngagementRecorder $engagement): JsonResponse
    {
        $tenant = Site::withoutGlobalScope(SiteScope::class)->find($site);
        $secret = $tenant !== null ? $credentials->webhookSecret($tenant) : null;
        $given = (string) $request->header(self::HEADER, '');

        if ($tenant === null || $secret === null || ! hash_equals($secret, $given)) {
            return response()->json(['ok' => false], 401);
        }

        $email = $request->input('lead_email');
        $event = $request->input('event_type');
        if (! is_string($email) || $email === '' || ! is_string($event)) {
            return response()->json(['ok' => true, 'ignored' => 'no lead']);
        }

        $contact = ColdContact::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $tenant->id)
            ->where('email_key', ColdContact::keyFor($email))
            ->first();
        if ($contact === null) {
            return response()->json(['ok' => true, 'ignored' => 'unknown lead']);
        }

        $config = ColdOutreachConfig::forSite($tenant->id);
        $wasWarm = $contact->stage === ColdContactStage::Warm;
        $engagement->applyEvent($contact, $event, $config);

        // A contact that just turned warm goes to Brevo now rather than waiting for the hourly sweep.
        if (! $wasWarm && $contact->stage === ColdContactStage::Warm && $config->auto_promote) {
            AdvanceColdPipeline::dispatch($tenant->id);
        }

        return response()->json(['ok' => true, 'stage' => $contact->stage->value]);
    }
}
