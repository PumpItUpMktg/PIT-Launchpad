<?php

namespace App\Publishing;

use App\Enums\ContentStatus;
use App\Enums\LaunchRunStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\LaunchRun;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Throwable;

/**
 * The launch orchestrator: sequences a full built site's push to its connected
 * WordPress instance, in order — silos → content → redirects. It WRAPS the
 * existing §2 per-entity publishers (PublishSiloService / PublishContentService /
 * PublishRedirectsService), which already own the transport and the contract
 * protocol (idempotent ULID upserts, the {skipped:true} guard, wp-id storage).
 *
 * What this layer adds:
 *  - the launch gate — refuse unless a present, non-compromised WordPress
 *    connection exists (a compromised/unrotated credential blocks go-live);
 *  - per-item failure isolation — one silo/page/redirect failing records its
 *    error and the launch continues; a failure never aborts the whole push;
 *  - a LaunchRun record — the operator's go-live audit of what pushed, what was
 *    skipped (and why), what failed (and why), with the returned WP ids.
 *
 * It does NOT flip SiteStatus::Live — that lifecycle step is the §9 handover
 * (SiteLauncher), kept separate.
 */
class LaunchOrchestrator
{
    // The launchable set (approved or further along; never drafts/candidates/
    // rejected) is the publishable set PublishContentService enforces — single-
    // sourced there so the launch filter and the publish guard can't drift.

    public function __construct(
        private readonly PublishSiloService $silos,
        private readonly PublishContentService $contents,
        private readonly PublishRedirectsService $redirects,
        private readonly ConnectionGate $gate,
    ) {}

    public function launch(Site $site, ?string $actorId = null): LaunchRun
    {
        $run = new LaunchRun([
            'site_id' => $site->id,
            'status' => LaunchRunStatus::Running->value,
            'pushed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
            'actor_id' => $actorId,
            'started_at' => now(),
        ]);

        // Launch gate: a present, non-compromised WordPress connection.
        if (! $this->gate->hasVerifiedWordpress($site->id)) {
            $run->forceFill(['status' => LaunchRunStatus::Blocked->value, 'completed_at' => now()]);
            $run->recordItem('connection', $site->id, 'WordPress connection', 'failed',
                'No present, non-compromised WordPress connection — wire and verify one first.');
            $run->save();

            return $run;
        }

        $run->save();

        $this->pushSilos($site, $run);
        $this->pushContent($site, $run);
        $this->pushRedirects($site, $run);

        $run->forceFill(['status' => LaunchRunStatus::Completed->value, 'completed_at' => now()])->save();

        return $run;
    }

    private function pushSilos(Site $site, LaunchRun $run): void
    {
        // Roots first so the parent mapping is clean (the plugin lazy-creates
        // anyway, so this is best-effort ordering, not a hard dependency).
        $silos = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByRaw('parent_silo_id is not null')
            ->orderBy('created_at')
            ->get();

        foreach ($silos as $silo) {
            try {
                $response = $this->silos->publish($silo);
                $run->recordItem('silo', $silo->id, $silo->name, 'pushed', '',
                    isset($response['wp_category_id']) ? (int) $response['wp_category_id'] : null);
            } catch (Throwable $e) {
                $run->recordItem('silo', $silo->id, $silo->name, 'failed', $e->getMessage());
            }
            $run->save();
        }
    }

    private function pushContent(Site $site, LaunchRun $run): void
    {
        $contents = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', array_map(fn (ContentStatus $s) => $s->value, PublishContentService::PUBLISHABLE))
            ->get()
            // DEPENDENCY-SAFE ORDER (leaves-first): a page that links to other pages must go live AFTER
            // them, because the "Our services" grid + internal links only resolve to pages already on
            // WordPress. So: all pages before posts, then by PageType::publishRank (service → hub →
            // location → home), then created_at. This is why a fresh launch's Home ships with a full
            // services grid instead of an empty one. Publishing runs synchronously here, so the order holds.
            ->sortBy(fn (Content $content): string => $this->publishSortKey($content))
            ->values();

        foreach ($contents as $content) {
            $label = $content->title !== '' ? $content->title : $content->slug;

            try {
                $result = $this->contents->publish($content);
                $state = match (true) {
                    $result->isPublished() => 'pushed',
                    $result->wasSkipped() => 'skipped',
                    default => 'failed', // blocked (required image) or push failure
                };
                $run->recordItem('content', $content->id, $label, $state, $result->message, $result->wpPostId);
            } catch (Throwable $e) {
                $run->recordItem('content', $content->id, $label, 'failed', $e->getMessage());
            }
            $run->save();
        }
    }

    /**
     * A sortable key encoding the dependency-safe publish order: pages before posts, then the
     * {@see PageType::publishRank} (leaves-first), then created_at, then id for a stable tiebreak.
     */
    private function publishSortKey(Content $content): string
    {
        $kindRank = $content->kind->value === 'post' ? 1 : 0;
        $typeRank = $content->page_type?->publishRank() ?? 99;
        $created = $content->created_at?->getTimestamp() ?? 0;

        return sprintf('%d_%02d_%012d_%s', $kindRank, $typeRank, $created, $content->id);
    }

    private function pushRedirects(Site $site, LaunchRun $run): void
    {
        // One full-set replace for the whole site (the plugin upserts by from_url).
        try {
            $response = $this->redirects->publish($site);
            $count = (int) ($response['count'] ?? 0);
            $run->recordItem('redirects', $site->id, 'Redirects', 'pushed', "{$count} redirect(s) set");
        } catch (Throwable $e) {
            $run->recordItem('redirects', $site->id, 'Redirects', 'failed', $e->getMessage());
        }
        $run->save();
    }
}
