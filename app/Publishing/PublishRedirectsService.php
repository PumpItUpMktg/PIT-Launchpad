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
            ->map(fn (Redirect $r): array => self::row($r))
            ->all();

        return $this->wordpress->forSite($site)->upsertRedirects($redirects);
    }

    /**
     * A 4xx row (410 Gone) has no destination by design, and the plugin's store drops any row whose
     * destination is empty before it keys the map — so a flush written with --gone never reached the live
     * site. The plugin emits a 4xx status before it looks at the destination at all, so a flush is sent
     * with the root as a placeholder: the status is what the plugin serves, the placeholder only carries
     * the row past the store's empty-destination check.
     *
     * @return array{from_url: string, to_url: string, code: int}
     */
    public static function row(Redirect $r): array
    {
        $code = (int) $r->code;
        $to = PublicUrl::redirectTarget((string) $r->to_url);
        if ($code >= 400 && $to === '') {
            $to = self::GONE_PLACEHOLDER;
        }

        return ['from_url' => (string) $r->from_url, 'to_url' => $to, 'code' => $code];
    }

    /** The destination sent with a 410 row so the plugin keeps it; never followed, the 4xx status is served first. */
    public const GONE_PLACEHOLDER = '/';
}
