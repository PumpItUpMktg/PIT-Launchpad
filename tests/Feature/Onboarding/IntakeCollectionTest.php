<?php

use App\Enums\ConnectionProvider;
use App\Enums\SiteStatus;
use App\Enums\VoiceStatus;
use App\Models\Account;
use App\Models\Competitor;
use App\Models\ConversionConfig;
use App\Models\Goal;
use App\Models\Keyword;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\SiteBranding;
use App\Models\SourceDocument;
use App\Models\VoiceProfile;
use App\Onboarding\IntakeCollector;

function collector(): IntakeCollector
{
    return app(IntakeCollector::class);
}

test('the wizard collects all seven buckets into the correct §1 entities', function () {
    $account = Account::factory()->create();
    $collector = collector();

    // Step 1 — Account + WordPress credential.
    $site = $collector->createSite($account, ['brand_name' => 'Apex Plumbing', 'domain_url' => 'https://apex.example']);
    expect($site->status)->toBe(SiteStatus::Onboarding);

    $wp = $collector->storeWordPressCredential($site, ['app_password' => 'abcd efgh ijkl', 'user' => 'launchpad-sync']);
    expect($wp->provider)->toBe(ConnectionProvider::WpAppPassword)
        ->and($wp->fresh()->credentials)->toBe(['app_password' => 'abcd efgh ijkl', 'user' => 'launchpad-sync']);

    // Step 2 — Identity + GBP connect.
    $identity = $collector->saveIdentity($site,
        ['palette' => ['primary' => '#0F62FE'], 'entity_type' => 'LocalBusiness'],
        ['name' => 'HQ', 'phone' => '512-555-0100', 'is_storefront' => true],
    );
    expect($identity['branding'])->toBeInstanceOf(SiteBranding::class)
        ->and($identity['location']->is_storefront)->toBeTrue();
    expect($collector->connectGbp($site, ['token' => 'gbp-token'])->provider)->toBe(ConnectionProvider::Gbp);

    // Step 3 — Service Catalog (GBP-seeded checklist + saved services).
    $checklist = $collector->serviceChecklist('plumber');
    expect($checklist)->toContain('Drain Cleaning');

    $services = $collector->saveServiceCatalog($site, [
        ['name' => 'Water Heater Repair', 'silo_role' => 'pillar', 'is_most_profitable' => true, 'problems' => [['phrase' => 'water heater leaking', 'intent' => 'transactional']]],
        ['name' => 'Drain Cleaning', 'silo_role' => 'supporting'],
    ]);
    expect($services)->toHaveCount(2)
        ->and(Service::withoutGlobalScopes()->count())->toBe(2)
        ->and(ServiceProblem::count())->toBe(1)
        ->and($services->first()->is_most_profitable)->toBeTrue();

    // Step 4 — Markets/Geo with Census enrichment.
    $markets = $collector->saveMarkets($site, [
        ['name' => 'Austin', 'geo_id' => '48453', 'tier' => 'priority', 'lat' => 30.26, 'lng' => -97.74],
        ['name' => 'Round Rock', 'geo_id' => '48491', 'tier' => 'coverage'],
    ]);
    expect($markets)->toHaveCount(2)
        ->and($markets->first()->demographics)->toHaveKey('population');

    // Step 5 — Proof.
    $proof = $collector->saveProof($site, [
        ['type' => 'warranty', 'is_substantiated' => true, 'service_ids' => [$services->first()->id], 'market_ids' => [$markets->first()->id]],
    ]);
    expect(ProofItem::withoutGlobalScopes()->count())->toBe(1)
        ->and($proof->first()->services)->toHaveCount(1)
        ->and($proof->first()->markets)->toHaveCount(1);

    // Step 6 — Targets/Conversion.
    $collector->saveTargets($site, [
        'competitors' => [['name' => 'Rival Plumbing', 'domain' => 'rival.example']],
        'keywords' => ['water heater repair austin'],
        'goals' => [['metric' => 'leads', 'target' => 120, 'period' => 'monthly']],
        'conversion' => ['primary_actions' => ['call', 'book']],
        'offers' => [['name' => '$50 off', 'service_ids' => [$services->first()->id]]],
    ]);
    expect(Competitor::withoutGlobalScopes()->count())->toBe(1)
        ->and(Keyword::withoutGlobalScopes()->count())->toBe(1)
        ->and(Goal::withoutGlobalScopes()->count())->toBe(1)
        ->and(ConversionConfig::withoutGlobalScopes()->where('site_id', $site->id)->first()->primary_actions)->toBe(['call', 'book'])
        ->and(Offer::withoutGlobalScopes()->count())->toBe(1);

    // Step 7 — Assets.
    $collector->saveAssets($site, [
        'media' => [['kind' => 'photo', 'r2_key' => 'm/1.webp', 'alt_text' => 'A water heater']],
        'source_documents' => [['type' => 'warranty_pdf', 'r2_key' => 'd/1.pdf']],
        'redirects' => [['from_url' => '/old', 'to_url' => '/new']],
    ]);
    expect(MediaAsset::withoutGlobalScopes()->count())->toBe(1)
        ->and(SourceDocument::withoutGlobalScopes()->count())->toBe(1)
        ->and(Redirect::withoutGlobalScopes()->count())->toBe(1);
});

test('the voice interview synthesises and activates a VoiceProfile', function () {
    $account = Account::factory()->create();
    $collector = collector();
    $site = $collector->createSite($account, ['brand_name' => 'Apex']);

    $v1 = $collector->synthesizeVoice($site, ['warmth' => 0.8, 'identity' => 'family-owned plumber']);
    expect($v1->status)->toBe(VoiceStatus::Draft)
        ->and($v1->version)->toBe(1)
        ->and($v1->persona['identity'])->toBe('family-owned plumber');

    $collector->activateVoice($v1);
    expect($v1->fresh()->status)->toBe(VoiceStatus::Active);

    // A second synthesis + activation archives the first (one active per site).
    $v2 = $collector->synthesizeVoice($site, ['warmth' => 0.5]);
    expect($v2->version)->toBe(2);
    $collector->activateVoice($v2);

    expect($v1->fresh()->status)->toBe(VoiceStatus::Archived)
        ->and($v2->fresh()->status)->toBe(VoiceStatus::Active)
        ->and(VoiceProfile::withoutGlobalScopes()->where('site_id', $site->id)->where('status', 'active')->count())->toBe(1);
});
