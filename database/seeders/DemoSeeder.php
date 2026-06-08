<?php

namespace Database\Seeders;

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\MediaKind;
use App\Enums\ProofType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Competitor;
use App\Models\Connection;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\ConversionConfig;
use App\Models\Goal;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\MediaAsset;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\Source;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use Illuminate\Database\Seeder;

/**
 * A single, coherent demo tenant: one account/site with the full §1 spine wired
 * together. This fixture is the substrate later sections develop against.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Account + Site (the tenant) ----------------------------------------
        $account = Account::factory()->direct()->create([
            'name' => 'Apex Home Services',
        ]);

        $site = Site::factory()->for($account)->create([
            'brand_name' => 'Apex Plumbing & HVAC',
            'legal_name' => 'Apex Home Services LLC',
            'domain_url' => 'https://apexplumbing.example',
        ]);

        SiteBranding::factory()->create(['site_id' => $site->id]);
        ConversionConfig::factory()->create(['site_id' => $site->id]);

        // Users + access -----------------------------------------------------
        $operator = User::factory()->create([
            'name' => 'Demo Operator',
            'email' => 'operator@apex.example',
            'role' => UserRole::Operator,
        ]);

        $client = User::factory()->create([
            'name' => 'Demo Client',
            'email' => 'client@apex.example',
            'role' => UserRole::Client,
        ]);

        Membership::create([
            'user_id' => $operator->id,
            'account_id' => $account->id,
            'role' => UserRole::Operator,
        ]);

        Membership::create([
            'user_id' => $client->id,
            'account_id' => $account->id,
            'site_id' => $site->id,
            'role' => UserRole::Client,
        ]);

        // Identity -----------------------------------------------------------
        Location::factory()->create([
            'site_id' => $site->id,
            'name' => 'Main Office',
            'is_storefront' => true,
        ]);

        // Silo spine: a service pillar with a nested topical silo ------------
        $pillar = Silo::factory()->servicePillar()->create([
            'site_id' => $site->id,
            'name' => 'Plumbing',
        ]);

        $topical = Silo::factory()->topical()->create([
            'site_id' => $site->id,
            'name' => 'Water Heaters',
            'parent_silo_id' => $pillar->id,
        ]);

        // Services + problem inventory ---------------------------------------
        $services = collect(['Water Heater Repair', 'Drain Cleaning', 'Leak Detection'])
            ->map(fn (string $name) => Service::factory()->create([
                'site_id' => $site->id,
                'name' => $name,
            ]));

        $services->each(fn (Service $service) => ServiceProblem::factory()
            ->count(2)
            ->create(['service_id' => $service->id]));

        $pillar->services()->attach($services->pluck('id')->all());
        $topical->services()->attach($services->first()->id);

        // Markets ------------------------------------------------------------
        $priorityMarket = Market::factory()->priority()->create([
            'site_id' => $site->id,
            'name' => 'Austin',
            'is_covered' => true,
        ]);

        $coverageMarket = Market::factory()->coverage()->create([
            'site_id' => $site->id,
            'name' => 'Round Rock',
        ]);

        $priorityMarket->services()->attach($services->pluck('id')->all());
        $coverageMarket->services()->attach($services->first()->id);

        // Voice (one active version) -----------------------------------------
        VoiceProfile::factory()->active()->create([
            'site_id' => $site->id,
            'version' => 1,
        ]);

        // Proof spanning several types ---------------------------------------
        $proofTypes = [
            ProofType::Warranty,
            ProofType::License,
            ProofType::ReviewAggregate,
            ProofType::Testimonial,
        ];

        foreach ($proofTypes as $type) {
            $proof = ProofItem::factory()->create([
                'site_id' => $site->id,
                'type' => $type,
                'is_substantiated' => true,
            ]);
            $proof->services()->attach($services->first()->id);
            $proof->markets()->attach($priorityMarket->id);
        }

        // Targets / conversion -----------------------------------------------
        $offer = Offer::factory()->create([
            'site_id' => $site->id,
            'name' => '$50 Off First Service',
        ]);
        $offer->services()->attach($services->first()->id);

        Goal::factory()->create([
            'site_id' => $site->id,
            'metric' => 'leads',
            'target' => 120,
            'period' => 'monthly',
        ]);

        Competitor::factory()->create([
            'site_id' => $site->id,
            'name' => 'Rival Plumbing Co',
        ]);

        // Content contract + intake ------------------------------------------
        $kit = WireframeKit::factory()->create([
            'site_id' => null, // library-level kit, shared across sites
            'name' => 'Service Page Kit',
        ]);

        $feed = Source::factory()->create([
            'site_id' => $site->id,
            'silo_id' => $topical->id,
        ]);

        // Keywords -----------------------------------------------------------
        $keyword = Keyword::factory()->create([
            'site_id' => $site->id,
            'silo_id' => $pillar->id,
            'query' => 'water heater repair austin',
            'source' => KeywordSource::ServiceProblem,
            'status' => 'scored',
        ]);

        // Content: one page and one post, wired to a silo + keyword ----------
        $page = Content::factory()->page()->create([
            'site_id' => $site->id,
            'silo_id' => $pillar->id,
            'wireframe_kit_id' => $kit->id,
            'target_keyword_id' => $keyword->id,
            'title' => 'Water Heater Repair in Austin',
            'slug' => 'water-heater-repair-austin',
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);

        $post = Content::factory()->post()->create([
            'site_id' => $site->id,
            'silo_id' => $topical->id,
            'source_id' => $feed->id,
            'target_keyword_id' => $keyword->id,
            'title' => 'Signs Your Water Heater Is Failing',
            'slug' => 'signs-your-water-heater-is-failing',
            'status' => ContentStatus::Drafted,
        ]);

        // Close the silo/keyword loops (deferred FKs) ------------------------
        $keyword->update(['target_content_id' => $page->id]);
        $pillar->update(['pillar_content_id' => $page->id]);

        // Append-only snapshot -----------------------------------------------
        ContentVersion::create([
            'content_id' => $page->id,
            'version' => 1,
            'payload_snapshot' => [
                'title' => $page->title,
                'slot_payload' => $page->slot_payload,
            ],
            'created_by' => $operator->id,
        ]);

        // Media + grounding --------------------------------------------------
        $media = MediaAsset::factory()->create([
            'site_id' => $site->id,
            'kind' => MediaKind::Photo,
            'rights_ok' => true,
        ]);
        $media->services()->attach($services->first()->id);
        $page->media()->attach($media->id);
        $post->media()->attach($media->id);

        SourceDocument::factory()->create([
            'site_id' => $site->id,
        ]);

        // Integrations -------------------------------------------------------
        Connection::factory()->create([
            'site_id' => $site->id,
            'provider' => ConnectionProvider::WpAppPassword,
        ]);
    }
}
