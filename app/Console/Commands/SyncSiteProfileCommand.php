<?php

namespace App\Console\Commands;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push a site's universal header/footer PROFILE (brand + NAP + navigation) to its companion plugin —
 * the data the [lp_header]/[lp_footer] chrome renders. Explicit, operator-invoked; run it after
 * onboarding or whenever the NAP / page set changes. The chrome is site-wide, so this is a site-level
 * push (not per-page).
 */
class SyncSiteProfileCommand extends Command
{
    protected $signature = 'launchpad:sync-site-profile {site : the Site id}';

    protected $description = 'Assemble a site profile (brand + NAP + nav) from §1 and push it to the companion plugin header/footer.';

    public function handle(SiteProfileAssembler $assembler, WordpressClientFactory $factory): int
    {
        $site = Site::find((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $profile = $assembler->assemble($site);

        try {
            $result = $factory->forSite($site)->pushSiteProfile($profile);
        } catch (Throwable $e) {
            $this->error(sprintf('Push failed for %s — %s', $site->brand_name, $e->getMessage()));

            return self::FAILURE;
        }

        if (empty($result['updated'])) {
            $this->error('The companion plugin rejected the profile push.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            "Pushed site profile for '%s' — %d services, %d areas, %d company links%s.",
            $site->brand_name,
            count($profile['services']),
            count($profile['areas']),
            count($profile['company']),
            $profile['phone'] !== '' ? ', phone set' : ', no phone',
        ));

        return self::SUCCESS;
    }
}
