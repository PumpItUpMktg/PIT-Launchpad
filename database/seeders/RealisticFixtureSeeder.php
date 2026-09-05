<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\MarketTier;
use App\Enums\PageType;
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
 * The tenant: a Trenton-NJ / Bucks-County-PA home-services business serving Mercer County NJ + Bucks
 * County PA and the surrounding Delaware-Valley municipalities.
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
            'brand_name' => 'Delaware Valley Plumbing & HVAC',
            'legal_name' => 'Delaware Valley Home Services LLC',
            'domain_url' => 'https://dvplumbing.example',
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
            'name' => 'Sam Rivera', 'email' => 'client@dvplumbing.example', 'role' => UserRole::Client,
        ]);
        Membership::create(['user_id' => $client->id, 'account_id' => $account->id, 'site_id' => $site->id, 'role' => UserRole::Client]);

        // Storefront locations (physical NAP) --------------------------------
        Location::factory()->create(['site_id' => $site->id, 'name' => 'Trenton Shop', 'is_storefront' => true]);
        Location::factory()->create(['site_id' => $site->id, 'name' => 'Newtown Shop', 'is_storefront' => true]);

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

        // Markets: 11 NJ/PA + Fallston MD on hold ----------------------------
        // [name, state, tier, population]
        $marketDefs = [
            ['Trenton', 'NJ', MarketTier::Priority, 90871],
            ['Hamilton Township', 'NJ', MarketTier::Priority, 92297],
            ['Princeton', 'NJ', MarketTier::Priority, 31249],
            ['Newtown', 'PA', MarketTier::Priority, 22586],
            ['Ewing', 'NJ', MarketTier::Coverage, 35790],
            ['Lawrence', 'NJ', MarketTier::Coverage, 33472],
            ['Hopewell', 'NJ', MarketTier::Coverage, 18565],
            ['Yardley', 'PA', MarketTier::Coverage, 26459],
            ['Levittown', 'PA', MarketTier::Coverage, 52983],
            ['Morrisville', 'PA', MarketTier::Coverage, 8763],
            ['Bristol', 'PA', MarketTier::Coverage, 9726],
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
                $areaRows[] = [
                    'id' => $areaId, 'site_id' => $site->id, 'geo_id' => (string) fake()->unique()->numerify('34######'),
                    'name' => $town, 'type' => 'place', 'state' => $m->region,
                    'population' => fake()->numberBetween(1500, 45000), 'size_tier' => 'medium',
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
    }

    /** Field jobs across the lifecycle: a review backlog, the publish pipeline, and a published body. */
    private function seedJobs(Site $site): void
    {
        // JobCity / JobCounty are shared geo-reference tables (not site-scoped).
        $mercer = JobCounty::factory()->create(['name' => 'Mercer County', 'state' => 'NJ', 'state_fips' => '34', 'county_geoid' => '34021', 'slug' => 'mercer-county-nj']);
        $bucks = JobCounty::factory()->create(['name' => 'Bucks County', 'state' => 'PA', 'state_fips' => '42', 'county_geoid' => '42017', 'slug' => 'bucks-county-pa']);

        $cities = collect([
            ['Trenton', 'NJ', $mercer], ['Princeton', 'NJ', $mercer], ['Hamilton', 'NJ', $mercer], ['Ewing', 'NJ', $mercer],
            ['Newtown', 'PA', $bucks], ['Yardley', 'PA', $bucks], ['Levittown', 'PA', $bucks], ['Bristol', 'PA', $bucks],
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

    /** @return list<string> */
    private function neighborhoods(string $market): array
    {
        return match ($market) {
            'Trenton' => ['Chambersburg', 'Mill Hill', 'Hiltonia'],
            'Princeton' => ['Riverside', 'Littlebrook'],
            'Newtown' => ['Newtown Grant', 'Wiltshire Walk'],
            default => ['Downtown', 'North End'],
        };
    }

    /** @return list<string> */
    private function townsFor(string $market, string $state, int $count): array
    {
        $pool = $state === 'NJ'
            ? ['Hopewell', 'Pennington', 'Robbinsville', 'Hightstown', 'East Windsor', 'West Windsor', 'Lambertville', 'Titusville', 'Yardville', 'Groveville', 'Mercerville', 'White Horse', 'Hamilton Square', 'Washington Crossing', 'Cranbury', 'Allentown', 'Bordentown', 'Roebling', 'Florence', 'Columbus', 'Chesterfield', 'Crosswicks', 'Windsor', 'Dutch Neck']
            : ['Langhorne', 'Richboro', 'Holland', 'Wrightstown', 'Washington Crossing', 'New Hope', 'Fairless Hills', 'Penndel', 'Feasterville', 'Southampton', 'Churchville', 'Newtown Grant', 'Buckingham', 'Doylestown', 'Warminster', 'Ivyland', 'Trevose', 'Croydon', 'Tullytown', 'Woodbourne', 'Oakford', 'Parkland', 'Village Shires', 'Penns Park'];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $i < count($pool) ? $pool[$i] : $pool[$i % count($pool)].' '.($state === 'NJ' ? 'Heights' : 'Station');
        }

        return $out;
    }

    private function siloForService(string $service, Silo $plumbing, Silo $hvac): Silo
    {
        return in_array($service, ['AC Repair', 'Furnace Installation', 'Boiler Repair'], true) ? $hvac : $plumbing;
    }
}
