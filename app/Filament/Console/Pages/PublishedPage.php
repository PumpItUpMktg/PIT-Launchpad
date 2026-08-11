<?php

namespace App\Filament\Console\Pages;

use App\Filament\Console\Concerns\SwapsPostImage;
use App\Jobs\PublishContent;
use App\OpsConsole\PublishedContentBoard;
use App\Security\Capability;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Shared base for the five Console → Published surfaces (Blog / Core Pages / Service Pages / Storefront
 * Pages / Location Pages) — each a stand-alone navigation item under the "Published" group. This splits
 * what used to be one tabbed page into five separate menu items; the read model, the card, and the
 * actions are unchanged.
 *
 * Every page reads the SAME {@see PublishedContentBoard} and renders the SAME rich card ({@see
 * PublishedContentBoard::card()}); a subclass only picks the slice it shows. The one action is Repush
 * (re-sync a live page via the idempotent {@see PublishContent} job), gated on the publish capability.
 */
abstract class PublishedPage extends ConsolePage
{
    use SwapsPostImage;

    protected static string|\UnitEnum|null $navigationGroup = 'Published';

    /**
     * The full published board for the active site + optional silo filter. Each subclass renders its own
     * slice; computing the whole board keeps a single source of truth (only one page renders at a time,
     * so there is no extra cost in practice).
     *
     * @return array{blog: list<array<string, mixed>>, core: list<array<string, mixed>>, service: list<array<string, mixed>>, storefronts: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        $board = app(PublishedContentBoard::class)->forSite($this->siteId, $this->siloId);

        // The brick-and-mortar town filter is a blog-post filter (it scans post copy); it is a no-op
        // unless the page opts in (only the Blog page does), so it is safe to apply here for all.
        $board['blog'] = $this->filterByStorefrontTown($board['blog']);

        return $board;
    }

    /** Re-sync a live page/post to WordPress (idempotent by ULID). */
    public function repush(string $contentId): void
    {
        if (! $this->can(Capability::PublishContent)) {
            return;
        }
        $content = $this->ownedContent($contentId);
        if ($content === null || $content->wp_post_id === null) {
            return;
        }

        PublishContent::dispatch((string) $content->id, Auth::id());

        Notification::make()->title('Re-syncing to WordPress.')->success()->send();
    }
}
