<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\SizeTier;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobCounty;
use App\Models\JobType;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\Membership;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A production-SHAPE fixture — one coherent tenant sized like a real client, so screenshots and manual
 * QA exercise the panels on realistic volume instead of invented toy data. Built once, reusable
 * (`php artisan db:seed --class=RealisticFixtureSeeder`); standalone (its own account/site, alongside
 * any DemoSeeder tenant).
 *
 * The tenant: a multi-metro NJ/PA home-services business. Markets are named for their GBP location
 * (the real naming convention — a market is a brick-and-mortar/service-area anchor, not a region).
 *
 *   • 12 markets — 11 across NJ & PA (4 Priority, 7 Coverage) + Fallston MD on an advisory hold.
 *   • 180 live town (location) pages, distributed across the active markets (Priority markets denser).
 *   • 47 live service pages (the service catalog across the Priority markets).
 *   • ~215 market-pinned keywords + silo-level keywords (drives the Markets board's per-market counts).
 *   • Field jobs across the capture → review → published lifecycle (drives the Jobs surface).
 *
 * The operator carries an ACCOUNT-WIDE membership (site_id null) — the shape that tripped the
 * VisibleSiteScope recursion — so this fixture also exercises the fixed gating path end to end.
 */
class RealisticFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::factory()->direct()->create(['name' => 'Keystone Home Services']);

        $site = Site::factory()->for($account)->create([
            'brand_name' => 'Keystone Plumbing & HVAC',
            'legal_name' => 'Keystone Home Services LLC',
            'domain_url' => 'https://keystoneplumbing.example',
            'status' => 'active',
        ]);

        SiteBranding::factory()->create(['site_id' => $site->id]);
        ConversionConfig::factory()->create(['site_id' => $site->id]);
        VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 1]);
        Connection::factory()->create(['site_id' => $site->id]);

        // Operator with an ACCOUNT-WIDE membership (the recursion-trigger shape, now safe).
        $operator = User::factory()->create([
            'name' => 'Dana Fielding', 'email' => 'operator@keystone.example', 'role' => UserRole::Operator,
        ]);
        Membership::create(['user_id' => $operator->id, 'account_id' => $account->id, 'role' => UserRole::Operator]);

        $client = User::factory()->create([
            'name' => 'Sam Rivera', 'email' => 'client@keystoneplumbing.example', 'role' => UserRole::Client,
        ]);
        Membership::create(['user_id' => $client->id, 'account_id' => $account->id, 'site_id' => $site->id, 'role' => UserRole::Client]);

        // Storefront locations (physical NAP) — each a market's GBP anchor --------------------
        Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'is_storefront' => true]);
        Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'is_storefront' => true]);

        // Silos + services ---------------------------------------------------
        $plumbing = Silo::factory()->servicePillar()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
        $hvac = Silo::factory()->servicePillar()->create(['site_id' => $site->id, 'name' => 'HVAC']);

        $serviceNames = [
            'Water Heater Repair', 'Water Heater Installation', 'Drain Cleaning', 'Sewer Line Repair',
            'Leak Detection', 'Sump Pump Installation', 'Toilet Repair', 'Faucet Installation',
            'AC Repair', 'Furnace Installation', 'Boiler Repair', 'Emergency Plumbing',
        ];
        $services = collect($serviceNames)->map(fn (string $name, int $i) => Service::factory()->create([
            'site_id' => $site->id,
            'name' => $name,
        ]));
        $plumbing->services()->attach($services->slice(0, 8)->pluck('id')->all());
        $hvac->services()->attach($services->slice(8)->pluck('id')->all());

        // Markets: 11 across NJ & PA (named for their GBP location) + Fallston MD on hold ------
        // [name, state, tier, population]
        $marketDefs = [
            ['Hoboken', 'NJ', MarketTier::Priority, 60419],
            ['New Brunswick', 'NJ', MarketTier::Priority, 55676],
            ['Montclair', 'NJ', MarketTier::Priority, 40921],
            ['Reading', 'PA', MarketTier::Priority, 95112],
            ['Hackensack', 'NJ', MarketTier::Coverage, 46030],
            ['Bedminster', 'NJ', MarketTier::Coverage, 8248],
            ['Hackettstown', 'NJ', MarketTier::Coverage, 9724],
            ['Trooper', 'PA', MarketTier::Coverage, 6035],
            ['Doylestown', 'PA', MarketTier::Coverage, 8280],
            ['Downingtown', 'PA', MarketTier::Coverage, 7891],
            ['Spring City', 'PA', MarketTier::Coverage, 3389],
        ];

        $markets = collect($marketDefs)->map(fn (array $d) => Market::factory()->create([
            'site_id' => $site->id,
            'name' => $d[0],
            'region' => $d[1],
            'tier' => $d[2],
            'demographics' => ['population' => $d[3]],
            'neighborhoods' => $this->neighborhoods($d[0]),
            'is_covered' => true,
            'on_hold' => false,
            'release_at' => null,
        ]));

        // Fallston MD — the deferred market on an advisory hold (release two months out).
        $fallston = Market::factory()->create([
            'site_id' => $site->id,
            'name' => 'Fallston',
            'region' => 'MD',
            'tier' => MarketTier::Coverage,
            'demographics' => ['population' => 9317],
            'neighborhoods' => ['Upper Crossroads', 'Federal Hill'],
            'is_covered' => false,
            'on_hold' => true,
            'release_at' => Carbon::now()->addMonths(2),
        ]);

        $priority = $markets->filter(fn (Market $m) => $m->tier === MarketTier::Priority);
        $coverage = $markets->filter(fn (Market $m) => $m->tier === MarketTier::Coverage);

        // 180 live town (location) pages, denser on Priority markets -----------
        $now = now();
        $townRows = [];
        $areaRows = [];
        $townPerPriority = 24;   // 4 × 24 = 96
        $townPerCoverage = 12;   // 7 × 12 = 84  → 180 total
        $slugSeen = [];
        $mkTowns = function (Market $m, int $count) use (&$townRows, &$areaRows, $site, $now, &$slugSeen): void {
            foreach ($this->townsFor($m->name, $m->region, $count) as $i => $town) {
                $base = Str::slug("{$town} {$m->region} plumber");
                $slug = isset($slugSeen[$base]) ? $base.'-'.substr((string) Str::ulid(), -4) : $base;
                $slugSeen[$slug] = true;
                $areaId = (string) Str::ulid();
                // Realistic population spread → size_tier derived by the SAME canonical logic the real
                // pipeline uses (SizeTier::forPopulation), so the tier-progression / gate axis is populated
                // realistically (major/large/medium/small), NOT flat. This is a DIFFERENT axis from the
                // market's MarketTier (Priority/Coverage) — both are seeded on purpose.
                $population = $this->townPopulation();
                $areaRows[] = [
                    'id' => $areaId, 'site_id' => $site->id, 'geo_id' => (string) fake()->unique()->numerify('34######'),
                    'name' => $town, 'type' => 'place', 'state' => $m->region,
                    'population' => $population, 'size_tier' => SizeTier::forPopulation($population)?->value,
                    'page_selected' => true, 'source' => 'radius', 'created_at' => $now, 'updated_at' => $now,
                ];
                $townRows[] = [
                    'id' => (string) Str::ulid(), 'site_id' => $site->id, 'market_id' => $m->id,
                    'kind' => 'page', 'page_type' => PageType::Location->value, 'status' => ContentStatus::Published->value,
                    'title' => "Plumber in {$town}, {$m->region}", 'slug' => $slug,
                    'published_at' => $now, 'wp_post_id' => fake()->numberBetween(1000, 99999),
                    'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        };
        $priority->each(fn (Market $m) => $mkTowns($m, $townPerPriority));
        $coverage->each(fn (Market $m) => $mkTowns($m, $townPerCoverage));

        foreach (array_chunk($areaRows, 200) as $chunk) {
            CoverageArea::insert($chunk);
        }
        foreach (array_chunk($townRows, 200) as $chunk) {
            Content::insert($chunk);
        }

        // 47 live service pages — the catalog across the Priority markets ------
        $serviceRows = [];
        $made = 0;
        foreach ($priority as $m) {
            foreach ($services as $service) {
                if ($made >= 47) {
                    break 2;
                }
                $svcSilo = $this->siloForService($service->name, $plumbing, $hvac);
                $serviceRows[] = [
                    'id' => (string) Str::ulid(), 'site_id' => $site->id, 'market_id' => $m->id, 'silo_id' => $svcSilo->id,
                    'kind' => 'page', 'page_type' => PageType::Service->value, 'status' => ContentStatus::Published->value,
                    'title' => "{$service->name} in {$m->name}, {$m->region}",
                    'slug' => Str::slug("{$service->name} {$m->name}"),
                    'published_at' => $now, 'wp_post_id' => fake()->numberBetween(1000, 99999),
                    'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
                $made++;
            }
        }
        Content::insert($serviceRows);

        // Keywords: market-pinned (Priority denser) + silo-level unpinned ------
        $kwRows = [];
        $heads = ['plumber', 'water heater repair', 'drain cleaning', 'emergency plumber', 'sewer repair', 'ac repair', 'furnace repair', 'sump pump'];
        $addKw = function (Market $m, int $count) use (&$kwRows, $site, $plumbing, $heads, $now): void {
            for ($i = 0; $i < $count; $i++) {
                $head = $heads[$i % count($heads)];
                $kwRows[] = [
                    'id' => (string) Str::ulid(), 'site_id' => $site->id, 'silo_id' => $plumbing->id, 'market_id' => $m->id,
                    'query' => "{$head} {$m->name} {$m->region}", 'intent' => 'transactional', 'source' => 'seed',
                    'volume' => fake()->numberBetween(30, 2400), 'difficulty' => fake()->numberBetween(8, 62),
                    'status' => 'scored', 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        };
        $priority->each(fn (Market $m) => $addKw($m, 35));
        $coverage->each(fn (Market $m) => $addKw($m, 10));
        $addKw($fallston, 5);
        // Silo-level (unpinned) keywords — no market.
        for ($i = 0; $i < 30; $i++) {
            $kwRows[] = [
                'id' => (string) Str::ulid(), 'site_id' => $site->id, 'silo_id' => $plumbing->id, 'market_id' => null,
                'query' => fake()->unique()->words(3, true), 'intent' => 'informational', 'source' => 'seed',
                'volume' => fake()->numberBetween(50, 6000), 'difficulty' => fake()->numberBetween(20, 80),
                'status' => 'scored', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($kwRows, 300) as $chunk) {
            Keyword::insert($chunk);
        }

        $this->seedJobs($site);
        $this->seedPositions($site);
    }

    /**
     * Two-lane position snapshots so the Rankings surface has real movement: an earlier + a latest
     * organic capture per keyword (→ improved / newly-ranked movers), latest local-pack captures per
     * market, and a few cannibalization cases (two owned URLs in one latest capture).
     */
    private function seedPositions(Site $site): void
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereNotNull('market_id')
            ->orderBy('id')->limit(90)->get(['id', 'market_id']);

        $then = now()->subDays(35);
        $latest = now()->startOfHour();
        $rows = [];
        $snap = function (string $kwId, ?string $marketId, string $lane, ?int $rank, ?string $url, $at) use (&$rows, $site): void {
            $rows[] = [
                'id' => (string) Str::ulid(), 'site_id' => $site->id, 'keyword_id' => $kwId, 'market_id' => $marketId,
                'lane' => $lane, 'rank' => $rank, 'ranking_url' => $url, 'captured_at' => $at,
                'created_at' => $at, 'updated_at' => $at,
            ];
        };

        foreach ($keywords as $i => $kw) {
            $url = "https://keystoneplumbing.example/p/{$i}";
            $mod = $i % 5;
            if ($mod === 0) {
                // newly ranked: nothing earlier, ranks now.
                $snap($kw->id, null, 'organic', null, null, $then);
                $snap($kw->id, null, 'organic', fake()->numberBetween(4, 18), $url, $latest);
            } elseif ($mod === 4) {
                // slipped / flat — not a mover.
                $snap($kw->id, null, 'organic', fake()->numberBetween(6, 20), $url, $then);
                $snap($kw->id, null, 'organic', fake()->numberBetween(21, 40), $url, $latest);
            } else {
                // improved: worse earlier → better now.
                $from = fake()->numberBetween(14, 40);
                $snap($kw->id, null, 'organic', $from, $url, $then);
                $snap($kw->id, null, 'organic', fake()->numberBetween(1, $from - 3), $url, $latest);
            }

            // A local-pack standing for the keyword's market (every other keyword).
            if ($i % 2 === 0) {
                $snap($kw->id, $kw->market_id, 'local_pack', fake()->numberBetween(1, 12), $url, $latest);
            }

            // A handful of cannibalization cases: a second owned URL in the same latest capture.
            if ($i % 23 === 0) {
                $snap($kw->id, null, 'organic', fake()->numberBetween(5, 15), "https://keystoneplumbing.example/alt/{$i}", $latest);
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            PositionSnapshot::insert($chunk);
        }
    }

    /** Field jobs across the lifecycle: a review backlog, the publish pipeline, and a published body. */
    private function seedJobs(Site $site): void
    {
        // JobCity / JobCounty are shared geo-reference tables (not site-scoped). Counties match the markets.
        $county = fn (string $name, string $st, string $fips, string $geoid) => JobCounty::factory()->create([
            'name' => $name, 'state' => $st, 'state_fips' => $fips, 'county_geoid' => $geoid, 'slug' => Str::slug("{$name} {$st}"),
        ]);
        $hudson = $county('Hudson County', 'NJ', '34', '34017');
        $middlesex = $county('Middlesex County', 'NJ', '34', '34023');
        $essex = $county('Essex County', 'NJ', '34', '34013');
        $bergen = $county('Bergen County', 'NJ', '34', '34003');
        $bucks = $county('Bucks County', 'PA', '42', '42017');
        $berks = $county('Berks County', 'PA', '42', '42011');
        $chester = $county('Chester County', 'PA', '42', '42029');

        $cities = collect([
            ['Hoboken', 'NJ', $hudson], ['New Brunswick', 'NJ', $middlesex], ['Montclair', 'NJ', $essex], ['Hackensack', 'NJ', $bergen],
            ['Doylestown', 'PA', $bucks], ['Reading', 'PA', $berks], ['Downingtown', 'PA', $chester], ['Trooper', 'PA', $chester],
        ])->map(fn (array $c) => [
            'city' => JobCity::factory()->create([
                'name' => $c[0], 'state' => $c[1], 'slug' => Str::slug($c[0].' '.$c[1]),
                'place_geoid' => fake()->unique()->numerify('##########'),
            ]),
            'county' => $c[2],
        ]);

        $types = collect(['Water Heater Repair', 'Drain Cleaning', 'Sump Pump Installation', 'Leak Detection', 'AC Repair'])
            ->map(fn (string $label) => JobType::factory()->create(['site_id' => $site->id, 'label' => $label, 'slug' => Str::slug($label)]));

        $make = function (JobStatus $status, int $n) use ($site, $cities, $types): void {
            for ($i = 0; $i < $n; $i++) {
                $pick = $cities->random();
                $job = Job::factory()->create([
                    'site_id' => $site->id,
                    'status' => $status,
                    'job_city_id' => $pick['city']->id,
                    'job_county_id' => $pick['county']->id,
                    'performed_at' => now()->subDays(fake()->numberBetween(1, 60)),
                    'source_description' => fake()->sentence(12),
                    'enhanced_description' => $status === JobStatus::Captured ? null : fake()->paragraph(),
                    'post_title' => $status === JobStatus::Captured ? null : ucfirst(fake()->words(4, true)),
                    'wp_post_id' => $status === JobStatus::Published ? fake()->numberBetween(1, 9999) : null,
                ]);
                $t = $types->random();
                $job->jobTypes()->create(['job_type_id' => $t->id, 'label' => $t->label, 'slug' => Str::slug($t->label)]);
            }
        };

        $make(JobStatus::Review, 7);        // the review backlog
        $make(JobStatus::Captured, 3);      // freshly captured, pre-enhance
        $make(JobStatus::Approved, 2);      // in the publish pipeline
        $make(JobStatus::PublishFailed, 1); // a stuck one
        $make(JobStatus::Published, 24);    // the published body of work
    }

    /**
     * A covered town's population, skewed like a real suburban service area — mostly small/medium with a
     * few larger towns — so SizeTier::forPopulation() yields a realistic major/large/medium/small mix
     * (thresholds 50k / 30k / 15k). Weighted: ~55% small, ~25% medium, ~15% large, ~5% major.
     */
    private function townPopulation(): int
    {
        $roll = fake()->numberBetween(1, 100);

        return match (true) {
            $roll <= 55 => fake()->numberBetween(1_800, 14_999),   // small  (~55%)
            $roll <= 80 => fake()->numberBetween(15_000, 29_999),  // medium (~25%)
            $roll <= 92 => fake()->numberBetween(30_000, 49_999),  // large  (~12%)
            default => fake()->numberBetween(50_000, 95_000),      // major  (~8%)
        };
    }

    /** @return list<string> */
    private function neighborhoods(string $market): array
    {
        return match ($market) {
            'Hoboken' => ['Uptown', 'The Waterfront', 'Southwest'],
            'Montclair' => ['Upper Montclair', 'Watchung Plaza'],
            'Reading' => ['Centre Park', 'Riverside'],
            'New Brunswick' => ['Fifth Ward', 'The Yard'],
            default => ['Downtown', 'North End'],
        };
    }

    /** @return list<string> Surrounding municipalities in the market's state (representative). */
    private function townsFor(string $market, string $state, int $count): array
    {
        $pool = $state === 'NJ'
            ? ['Weehawken', 'Union City', 'Secaucus', 'Bloomfield', 'Nutley', 'Belleville', 'Verona', 'Cedar Grove', 'Teaneck', 'Paramus', 'Fair Lawn', 'Ridgewood', 'Englewood', 'Fort Lee', 'Edison', 'Piscataway', 'Highland Park', 'Metuchen', 'Somerville', 'Bridgewater', 'Basking Ridge', 'Bernardsville', 'Chester', 'Long Valley', 'Washington', 'Clinton', 'Flemington', 'Cranford', 'Westfield', 'Millburn']
            : ['Wyomissing', 'Birdsboro', 'Pottstown', 'Boyertown', 'Hamburg', 'Kutztown', 'Fleetwood', 'Warrington', 'Chalfont', 'New Britain', 'Perkasie', 'Souderton', 'Lansdale', 'King of Prussia', 'Norristown', 'Phoenixville', 'Collegeville', 'Skippack', 'Royersford', 'Exton', 'West Chester', 'Coatesville', 'Kennett Square', 'Malvern', 'Paoli', 'Devon', 'Berwyn', 'Audubon', 'Trooper', 'Eagleville'];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $i < count($pool) ? $pool[$i] : $pool[$i % count($pool)].' '.($state === 'NJ' ? 'Township' : 'Borough');
        }

        return $out;
    }

    private function siloForService(string $service, Silo $plumbing, Silo $hvac): Silo
    {
        return in_array($service, ['AC Repair', 'Furnace Installation', 'Boiler Repair'], true) ? $hvac : $plumbing;
    }
}
