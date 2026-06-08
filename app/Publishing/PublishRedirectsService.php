<?php

namespace App\Publishing;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Pushes a site's active redirects to /redirects (slug changes, location-page
 * exceptions). The plugin upserts by from_url, so re-pushes never duplicate.
 */
class PublishRedirectsService
{
    public function __construct(
        private readonly WordpressClientFactory $wordpress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publish(Site $site): array
    {
        $redirects = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'active')
            ->get()
            ->map(fn (Redirect $r) => [
                'from_url' => $r->from_url,
                'to_url' => $r->to_url,
                'code' => (int) $r->code,
            ])
            ->all();

        return $this->wordpress->forSite($site)->upsertRedirects($redirects);
    }
}
