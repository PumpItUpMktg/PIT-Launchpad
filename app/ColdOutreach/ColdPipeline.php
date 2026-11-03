<?php

namespace App\ColdOutreach;

use App\Enums\ColdContactStage;
use App\Integrations\ColdOutreach\ColdOutreachException;
use App\Integrations\ColdOutreach\EmailVerifier;
use App\Integrations\ColdOutreach\MarketingPlatform;
use App\Integrations\ColdOutreach\WarmupPlatform;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The cold outreach pipeline for one site, step by step:
 *
 *   verify   Imported  → Verified | Risky | Invalid      (Bird batch lookup)
 *   enroll   Verified  → Warming                         (added to the site's Instantly campaign)
 *   sync     Warming   → Warm | Disqualified             (Instantly engagement poll; webhooks do the same live)
 *   promote  Warm      → Promoted                        (upserted into the site's Brevo list)
 *
 * Each step is bounded per run and idempotent: a contact only moves forward, and a re-run picks up
 * where the last one stopped. A per-contact failure is recorded on `last_error` and the step carries
 * on; a fatal failure (bad key, unpaid plan, missing campaign/list) stops that step and is reported.
 */
class ColdPipeline
{
    public const VERIFY_BATCH = 500;

    public const ENROLL_BATCH = 200;

    public const PROMOTE_BATCH = 200;

    public const SYNC_MAX_PAGES = 50;

    public function __construct(
        private readonly EmailVerifier $verifier,
        private readonly WarmupPlatform $warmup,
        private readonly MarketingPlatform $marketing,
        private readonly DeliverabilityGate $gate,
        private readonly EngagementRecorder $engagement,
    ) {}

    /**
     * Run every step once.
     *
     * @return array<string, array{done: int, error: ?string}>
     */
    public function advance(Site $site): array
    {
        $config = ColdOutreachConfig::forSite($site->id);

        $report = [];
        foreach (['verify', 'enroll', 'sync', 'promote'] as $step) {
            try {
                $report[$step] = ['done' => $this->{$step}($site, $config), 'error' => null];
            } catch (Throwable $e) {
                // One step failing (a key missing, a provider down) never blocks the others.
                $report[$step] = ['done' => 0, 'error' => $e->getMessage()];
                Log::warning('cold-outreach '.$step.' stopped', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    public function verify(Site $site, ?ColdOutreachConfig $config = null): int
    {
        $config ??= ColdOutreachConfig::forSite($site->id);
        $contacts = $this->contacts($site, ColdContactStage::Imported)->limit(self::VERIFY_BATCH)->get();
        if ($contacts->isEmpty()) {
            return 0;
        }

        $verdicts = $this->verifier->verify($site, $contacts->pluck('email')->map('strval')->values()->all());

        foreach ($contacts->values() as $i => $contact) {
            $verdict = $verdicts[$i] ?? null;
            if ($verdict === null) {
                continue;
            }
            $contact->forceFill([
                'stage' => $this->gate->stageFor($verdict, $config),
                'verify_result' => $verdict->result,
                'verify_confidence' => $verdict->confidence,
                'verify_flags' => $verdict->flags,
                'verify_reason' => $verdict->reason,
                'did_you_mean' => $verdict->didYouMean,
                'verified_at' => now(),
                'last_error' => null,
            ])->save();
        }

        return $contacts->count();
    }

    public function enroll(Site $site, ?ColdOutreachConfig $config = null): int
    {
        $config ??= ColdOutreachConfig::forSite($site->id);
        $contacts = $this->contacts($site, ColdContactStage::Verified)->limit(self::ENROLL_BATCH)->get();
        if ($contacts->isEmpty()) {
            return 0;
        }
        $campaign = $config->instantly_campaign_id;
        if ($campaign === null || $campaign === '') {
            throw new ColdOutreachException('No Instantly campaign set for this site.', fatal: true);
        }

        $done = 0;
        foreach ($contacts as $contact) {
            try {
                $lead = $this->warmup->addLead($site, $campaign, $contact);
            } catch (ColdOutreachException $e) {
                if ($e->fatal) {
                    throw $e;
                }
                $contact->forceFill(['last_error' => $e->getMessage()])->save();

                continue;
            }

            $contact->forceFill([
                'stage' => ColdContactStage::Warming,
                'instantly_lead_id' => $lead->id,
                'instantly_campaign_id' => $campaign,
                'warming_started_at' => now(),
                'last_error' => null,
            ])->save();
            $done++;
        }

        return $done;
    }

    /** Poll the campaign's leads and apply their engagement to the matching warming contacts. */
    public function sync(Site $site, ?ColdOutreachConfig $config = null): int
    {
        $config ??= ColdOutreachConfig::forSite($site->id);
        $campaigns = $this->contacts($site, ColdContactStage::Warming)
            ->whereNotNull('instantly_campaign_id')->distinct()->pluck('instantly_campaign_id')->map('strval')->all();

        $updated = 0;
        foreach ($campaigns as $campaign) {
            $warming = $this->contacts($site, ColdContactStage::Warming)
                ->where('instantly_campaign_id', $campaign)->get()->keyBy('email_key');
            $cursor = null;
            $pages = 0;
            do {
                $page = $this->warmup->listLeads($site, $campaign, $cursor);
                foreach ($page['leads'] as $lead) {
                    $contact = $warming->get(ColdContact::keyFor($lead->email));
                    if ($contact instanceof ColdContact) {
                        $this->engagement->applyLead($contact, $lead, $config);
                        $updated++;
                    }
                }
                $cursor = $page['next'];
            } while ($cursor !== null && ++$pages < self::SYNC_MAX_PAGES);
        }

        return $updated;
    }

    public function promote(Site $site, ?ColdOutreachConfig $config = null, bool $force = false): int
    {
        $config ??= ColdOutreachConfig::forSite($site->id);
        if (! $config->auto_promote && ! $force) {
            return 0;
        }

        return $this->promoteContacts($site, $config, $this->contacts($site, ColdContactStage::Warm)->limit(self::PROMOTE_BATCH)->get()->all());
    }

    /**
     * Send specific warm contacts to Brevo now (the operator's "Send to Brevo").
     *
     * @param  list<ColdContact>  $contacts
     */
    public function promoteContacts(Site $site, ColdOutreachConfig $config, array $contacts): int
    {
        if ($contacts === []) {
            return 0;
        }
        $list = $config->brevo_list_id;
        if ($list === null || $list <= 0) {
            throw new ColdOutreachException('No Brevo list set for this site.', fatal: true);
        }

        $done = 0;
        foreach ($contacts as $contact) {
            if ($contact->stage !== ColdContactStage::Warm) {
                continue;
            }
            try {
                $id = $this->marketing->upsertContact($site, $list, $contact);
            } catch (ColdOutreachException $e) {
                if ($e->fatal) {
                    throw $e;
                }
                $contact->forceFill(['last_error' => $e->getMessage()])->save();

                continue;
            } catch (Throwable $e) {
                $contact->forceFill(['last_error' => $e->getMessage()])->save();

                continue;
            }

            $contact->forceFill([
                'stage' => ColdContactStage::Promoted,
                'brevo_contact_id' => $id ?? $contact->brevo_contact_id,
                'promoted_at' => now(),
                'last_error' => null,
            ])->save();
            $done++;
        }

        return $done;
    }

    /** @return Builder<ColdContact> */
    private function contacts(Site $site, ColdContactStage $stage): Builder
    {
        return ColdContact::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('stage', $stage->value)
            ->orderBy('id');
    }
}
