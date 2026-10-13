<?php

namespace App\Publishing;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;

/**
 * Pushes a site's active redirects to /redirects (slug changes, location-page
 * exceptions). The plugin upserts by from_url, so re-pushes never duplicate.
 *
 * Targets are sent in PublicUrl's trailing-slash form. Rows are stored slash-less ("/hub/town-pa") by every
 * writer, and WordPress 301s that form to "/hub/town-pa/" — so every legacy redirect was two hops. The
 * normalization lives here, at the one exit to the plugin, so no writer has to know the permalink shape.
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
                'to_url' => PublicUrl::redirectTarget((string) $r->to_url),
                'code' => (int) $r->code,
            ])
            ->all();

        return $this->wordpress->forSite($site)->upsertRedirects($redirects);
    }
}
