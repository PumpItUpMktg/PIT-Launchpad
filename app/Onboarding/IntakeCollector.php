<?php

namespace App\Onboarding;

use App\Enums\ConnectionProvider;
use App\Enums\GeoApplicability;
use App\Enums\MarketTier;
use App\Enums\ProofType;
use App\Enums\ServiceSiloRole;
use App\Enums\VoiceStatus;
use App\Integrations\Census\CensusProvider;
use App\Integrations\Gbp\GbpProvider;
use App\Integrations\Voice\VoiceSynthesizer;
use App\Models\Account;
use App\Models\Competitor;
use App\Models\Connection;
use App\Models\ConversionConfig;
use App\Models\Goal;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SourceDocument;
use App\Models\VoiceProfile;
use Illuminate\Support\Collection;

/**
 * Persists each onboarding bucket into the §1 entities. Vendors (GBP, Census,
 * Claude/voice) sit behind adapter interfaces with mock defaults. The actual WP
 * plugin handshake is stubbed — the credential is captured and stored.
 */
class IntakeCollector
{
    public function __construct(
        private readonly GbpProvider $gbp,
        private readonly CensusProvider $census,
        private readonly VoiceSynthesizer $voice,
    ) {}

    // Step 1 — Account + WordPress connect -----------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSite(Account $account, array $data): Site
    {
        return Site::create([
            'account_id' => $account->id,
            'brand_name' => $data['brand_name'],
            'legal_name' => $data['legal_name'] ?? null,
            'domain_url' => $data['domain_url'] ?? null,
            'status' => 'onboarding',
        ]);
    }

    /**
     * Capture + persist the WP application-password credential. The plugin
     * handshake itself is §2/§8.
     *
     * @param  array<string, mixed>  $credential
     */
    public function storeWordPressCredential(Site $site, array $credential): Connection
    {
        return $this->upsertConnection($site, ConnectionProvider::WpAppPassword, $credential);
    }

    // Step 2 — Identity -------------------------------------------------------

    /**
     * @param  array<string, mixed>  $branding
     * @param  array<string, mixed>  $location
     * @return array{branding: SiteBranding, location: Location}
     */
    public function saveIdentity(Site $site, array $branding, array $location): array
    {
        $brandingModel = SiteBranding::updateOrCreate(
            ['site_id' => $site->id],
            [
                'palette' => $branding['palette'] ?? null,
                'typography' => $branding['typography'] ?? null,
                'logo_set' => $branding['logo_set'] ?? null,
                'social_handles' => $branding['social_handles'] ?? null,
                'entity_type' => $branding['entity_type'] ?? 'LocalBusiness',
                'canonical_nap' => $branding['canonical_nap'] ?? null,
            ],
        );

        $locationModel = Location::create([
            'site_id' => $site->id,
            'name' => $location['name'] ?? 'Main Office',
            'address' => $location['address'] ?? null,
            'phone' => $location['phone'] ?? null,
            'email' => $location['email'] ?? null,
            'is_storefront' => (bool) ($location['is_storefront'] ?? false),
        ]);

        return ['branding' => $brandingModel, 'location' => $locationModel];
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    public function connectGbp(Site $site, array $credential): Connection
    {
        return $this->upsertConnection($site, ConnectionProvider::Gbp, $credential);
    }

    // Step 3 — Service Catalog ------------------------------------------------

    /**
     * The GBP-category-seeded service checklist.
     *
     * @return list<string>
     */
    public function serviceChecklist(string $primaryCategory): array
    {
        return $this->gbp->serviceTypes($primaryCategory);
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return Collection<int, Service>
     */
    public function saveServiceCatalog(Site $site, array $services): Collection
    {
        return collect($services)->map(function (array $def) use ($site) {
            $service = Service::create([
                'site_id' => $site->id,
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'scope' => $def['scope'] ?? null,
                'silo_role' => ServiceSiloRole::from($def['silo_role'] ?? 'supporting'),
                'is_most_profitable' => (bool) ($def['is_most_profitable'] ?? false),
                'is_growth_priority' => (bool) ($def['is_growth_priority'] ?? false),
                'geo_applicability' => GeoApplicability::from($def['geo_applicability'] ?? 'all'),
                'primary_cta_intent' => $def['primary_cta_intent'] ?? null,
            ]);

            foreach ($def['problems'] ?? [] as $problem) {
                ServiceProblem::create([
                    'service_id' => $service->id,
                    'phrase' => is_array($problem) ? $problem['phrase'] : $problem,
                    'intent' => is_array($problem) ? ($problem['intent'] ?? null) : null,
                ]);
            }

            return $service;
        });
    }

    // Step 4 — Markets / Geo --------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $markets
     * @return Collection<int, Market>
     */
    public function saveMarkets(Site $site, array $markets): Collection
    {
        return collect($markets)->map(function (array $def) use ($site) {
            $geoId = $def['geo_id'] ?? null;

            return Market::create([
                'site_id' => $site->id,
                'name' => $def['name'],
                'geo_id' => $geoId,
                'region' => $def['region'] ?? null,
                'tier' => MarketTier::from($def['tier'] ?? 'coverage'),
                'lat' => $def['lat'] ?? null,
                'lng' => $def['lng'] ?? null,
                'demographics' => $geoId !== null ? $this->census->demographics((string) $geoId) : null,
            ]);
        });
    }

    // Step 5 — Proof ----------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $proofItems
     * @return Collection<int, ProofItem>
     */
    public function saveProof(Site $site, array $proofItems): Collection
    {
        return collect($proofItems)->map(function (array $def) use ($site) {
            $proof = ProofItem::create([
                'site_id' => $site->id,
                'type' => ProofType::from($def['type']),
                'payload' => $def['payload'] ?? null,
                'is_substantiated' => (bool) ($def['is_substantiated'] ?? false),
                'evidence' => $def['evidence'] ?? null,
            ]);

            if (! empty($def['service_ids'])) {
                $proof->services()->attach($def['service_ids']);
            }
            if (! empty($def['market_ids'])) {
                $proof->markets()->attach($def['market_ids']);
            }

            return $proof;
        });
    }

    // Step 6 — Targets / Conversion ------------------------------------------

    /**
     * @param  array<string, mixed>  $targets
     */
    public function saveTargets(Site $site, array $targets): void
    {
        foreach ($targets['competitors'] ?? [] as $competitor) {
            Competitor::create([
                'site_id' => $site->id,
                'name' => $competitor['name'],
                'domain' => $competitor['domain'] ?? null,
                'type' => $competitor['type'] ?? 'organic',
            ]);
        }

        foreach ($targets['keywords'] ?? [] as $keyword) {
            Keyword::create([
                'site_id' => $site->id,
                'query' => is_array($keyword) ? $keyword['query'] : $keyword,
                'source' => 'seed',
                'status' => 'candidate',
            ]);
        }

        foreach ($targets['goals'] ?? [] as $goal) {
            Goal::create([
                'site_id' => $site->id,
                'metric' => $goal['metric'],
                'target' => $goal['target'] ?? null,
                'period' => $goal['period'] ?? null,
            ]);
        }

        if (! empty($targets['conversion'])) {
            ConversionConfig::updateOrCreate(
                ['site_id' => $site->id],
                [
                    'primary_actions' => $targets['conversion']['primary_actions'] ?? [],
                    'tracked_numbers' => $targets['conversion']['tracked_numbers'] ?? null,
                    'lead_destination' => $targets['conversion']['lead_destination'] ?? null,
                    'analytics_ids' => $targets['conversion']['analytics_ids'] ?? null,
                ],
            );
        }

        foreach ($targets['offers'] ?? [] as $offer) {
            $model = Offer::create([
                'site_id' => $site->id,
                'name' => $offer['name'],
                'terms' => $offer['terms'] ?? null,
            ]);
            if (! empty($offer['service_ids'])) {
                $model->services()->attach($offer['service_ids']);
            }
        }
    }

    // Step 7 — Assets ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $assets
     */
    public function saveAssets(Site $site, array $assets): void
    {
        foreach ($assets['media'] ?? [] as $media) {
            MediaAsset::create([
                'site_id' => $site->id,
                'kind' => $media['kind'] ?? 'photo',
                'source' => $media['source'] ?? 'uploaded',
                'r2_key' => $media['r2_key'] ?? null,
                'alt_text' => $media['alt_text'] ?? null,
                'rights_ok' => (bool) ($media['rights_ok'] ?? true),
            ]);
        }

        foreach ($assets['source_documents'] ?? [] as $doc) {
            SourceDocument::create([
                'site_id' => $site->id,
                'type' => $doc['type'] ?? 'spec',
                'r2_key' => $doc['r2_key'] ?? null,
                'grounding_enabled' => (bool) ($doc['grounding_enabled'] ?? true),
                'extracted_text' => $doc['extracted_text'] ?? null,
            ]);
        }

        foreach ($assets['redirects'] ?? [] as $redirect) {
            Redirect::create([
                'site_id' => $site->id,
                'from_url' => $redirect['from_url'],
                'to_url' => $redirect['to_url'],
                'source' => $redirect['source'] ?? 'migration',
            ]);
        }
    }

    // Step 8 — Voice interview -----------------------------------------------

    /**
     * @param  array<string, mixed>  $interview
     */
    public function synthesizeVoice(Site $site, array $interview): VoiceProfile
    {
        $draft = $this->voice->synthesize($interview);

        $version = (int) VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->max('version');

        return VoiceProfile::create([
            'site_id' => $site->id,
            'version' => $version + 1,
            'status' => VoiceStatus::Draft,
            'framing_model' => $draft['framing_model'] ?? 'problem_solution',
            'tone_axes' => $draft['tone_axes'] ?? null,
            'reading_level' => $draft['reading_level'] ?? null,
            'persona' => $draft['persona'] ?? null,
            'language_rules' => $draft['language_rules'] ?? null,
            'audience' => $draft['audience'] ?? null,
            'cta_voice' => $draft['cta_voice'] ?? null,
        ]);
    }

    /**
     * Operator QA: activate a drafted voice profile (one active per site).
     */
    public function activateVoice(VoiceProfile $profile): void
    {
        VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $profile->site_id)
            ->where('status', VoiceStatus::Active->value)
            ->whereKeyNot($profile->id)
            ->update(['status' => VoiceStatus::Archived->value]);

        $profile->update(['status' => VoiceStatus::Active]);
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function upsertConnection(Site $site, ConnectionProvider $provider, array $credential): Connection
    {
        return Connection::updateOrCreate(
            ['site_id' => $site->id, 'provider' => $provider->value],
            ['credentials' => $credential, 'status' => 'active', 'last_rotated_at' => now()],
        );
    }
}
