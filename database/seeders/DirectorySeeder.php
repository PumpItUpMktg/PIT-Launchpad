<?php

namespace Database\Seeders;

use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Enums\SubmissionMethod;
use App\Models\Directory;
use Illuminate\Database\Seeder;

/**
 * Seeds the GLOBAL citation catalog with the highest-weight national directories every local business should
 * be on (§ Citations). Idempotent (keyed on domain), so it is safe to re-run and safe to call alongside the
 * demo seeder — operators add niche / geo directories on top via the Directories admin resource.
 *
 * `seo_value` is left null on purpose: `launchpad:rate-directories` computes it from domain_rank. The big
 * platform listings (Google / Facebook / Apple / Bing) are flagged `requires_client_action` — they are
 * confirmed through the platform's own integrations, not the organic citation scan — so operators know not to
 * chase them as ordinary directory submissions.
 */
class DirectorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->directories() as $row) {
            Directory::query()->updateOrCreate(['domain' => $row['domain']], $row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function directories(): array
    {
        $f = SubmissionMethod::Form;
        $acct = SubmissionMethod::RequiresAccount;
        $nat = DirectoryScope::National;
        $perAddress = MultiLocationPolicy::OnePerAddress;
        $perBusiness = MultiLocationPolicy::OnePerBusiness;

        // domain, name, tier(1-5), domain_rank(0-100), business_value(0-100), method, policy, requires_client_action, notes
        $rows = [
            ['google.com', 'Google Business Profile', 5, 100, 100, $acct, $perAddress, true, 'Confirmed via the platform GBP integration, not the organic scan.'],
            ['yelp.com', 'Yelp', 5, 93, 95, $f, $perAddress, false, null],
            ['facebook.com', 'Facebook', 5, 96, 85, $acct, $perBusiness, true, 'Confirmed via the platform integration; one page per business.'],
            ['bbb.org', 'Better Business Bureau', 4, 92, 88, $f, $perAddress, false, null],
            ['angi.com', 'Angi', 4, 90, 88, $f, $perAddress, false, 'Home-services heavy.'],
            ['maps.apple.com', 'Apple Maps', 4, 91, 80, $acct, $perAddress, true, 'Confirmed via Apple Business Connect.'],
            ['bingplaces.com', 'Bing Places', 4, 90, 75, $acct, $perAddress, true, 'Confirmed via Bing Places account.'],
            ['homeadvisor.com', 'HomeAdvisor', 3, 89, 82, $f, $perAddress, false, null],
            ['thumbtack.com', 'Thumbtack', 3, 87, 80, $acct, $perAddress, false, null],
            ['yellowpages.com', 'Yellow Pages', 3, 92, 70, $f, $perAddress, false, null],
            ['foursquare.com', 'Foursquare', 3, 92, 60, $acct, $perAddress, false, null],
            ['nextdoor.com', 'Nextdoor', 3, 90, 78, $acct, $perAddress, false, null],
            ['houzz.com', 'Houzz', 3, 90, 72, $f, $perAddress, false, 'Home / remodeling audience.'],
            ['mapquest.com', 'MapQuest', 3, 90, 55, $f, $perAddress, false, null],
            ['manta.com', 'Manta', 2, 82, 50, $f, $perAddress, false, null],
            ['chamberofcommerce.com', 'ChamberofCommerce.com', 2, 85, 55, $f, $perAddress, false, null],
            ['superpages.com', 'Superpages', 2, 84, 45, $f, $perAddress, false, null],
            ['citysearch.com', 'Citysearch', 2, 80, 40, $f, $perAddress, false, null],
            ['hotfrog.com', 'Hotfrog', 2, 78, 35, $f, $perAddress, false, null],
            ['brownbook.net', 'Brownbook', 1, 70, 25, $f, $perAddress, false, null],
        ];

        return array_map(fn (array $r): array => [
            'domain' => $r[0],
            'slug' => trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $r[0])), '-'),
            'homepage_url' => 'https://'.$r[0],
            'name' => $r[1],
            'scope' => $nat,
            'authority_tier' => $r[2],
            'domain_rank' => $r[3],
            'business_value' => $r[4],
            'submission_method' => $r[5],
            'multi_location_policy' => $r[6],
            'requires_client_action' => $r[7],
            'is_submittable' => ! $r[7],
            'notes' => $r[8],
            'is_active' => true,
            'is_nofollow' => false,
        ], $rows);
    }
}
