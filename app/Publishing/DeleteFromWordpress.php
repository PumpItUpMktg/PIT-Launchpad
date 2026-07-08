<?php

namespace App\Publishing;

use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Throwable;

/**
 * Deletes a page's live WordPress post via the companion plugin's authed `launchpad/v1/content/delete`
 * endpoint ({@see WordpressClient::deleteContent}) — a server-side force
 * delete (never trash: a trashed post still reserves its slug, so a follow-up publish would land on
 * `slug-2`). Force-delete frees the slug, and because the control-plane keeps the Content row (and its
 * slug) and clears `wp_post_id`, a Repush recreates the page on the SAME permalink — no `-2`.
 *
 * The Content is flipped back to `approved` (ready to publish) and its stale WP-edit flag cleared, so
 * Repush isn't blocked by the locally-edited guard on a post that no longer exists. A failed delete
 * throws (with WordPress's reason) and leaves the page exactly as it was — never stranded republishable.
 */
final class DeleteFromWordpress
{
    public function __construct(private readonly WordpressClientFactory $factory) {}

    /**
     * @return array{deleted: bool, on_wp: bool, message: string}
     */
    public function delete(Content $content): array
    {
        $wpId = (int) ($content->wp_post_id ?? 0);
        $site = Site::withoutGlobalScope(SiteScope::class)->find($content->site_id);

        if ($wpId <= 0 || $site === null) {
            $this->makeRepublishable($content);

            return ['deleted' => false, 'on_wp' => false, 'message' => 'This page was not on WordPress; it is ready to publish.'];
        }

        // Delete through the companion plugin's authed launchpad/v1 endpoint (by control-plane ULID), not
        // the core WP route the service user can't authorize — see {@see WordpressClient::deleteContent}.
        try {
            $this->factory->forSite($site)->deleteContent((string) $content->id);
        } catch (Throwable $e) {
            // deleteContent confirms or throws (with the HTTP status + WordPress's reason), so a failure
            // here carries WHY — and the page is left exactly as it was, still live, never stranded.
            return ['deleted' => false, 'on_wp' => true, 'message' => 'WordPress delete failed: '.$e->getMessage()];
        }

        $this->makeRepublishable($content);

        return ['deleted' => true, 'on_wp' => true, 'message' => 'Deleted from WordPress — the slug is free; Repush recreates it on the same URL.'];
    }

    private function makeRepublishable(Content $content): void
    {
        $content->forceFill([
            'wp_post_id' => null,
            'status' => ContentStatus::Approved,
            'locally_edited' => false,
            'last_publish_error' => null,
        ])->save();
    }
}
