<?php

namespace App\Publishing;

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\LaunchRunStatus;
use App\Models\Connection;
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
    /** Content that belongs on a launched site: approved or further along (never drafts/candidates/rejected). */
    private const LAUNCHABLE = [
        ContentStatus::Approved,
        ContentStatus::Rendering,
        ContentStatus::Publishing,
        ContentStatus::Published,
        ContentStatus::RenderFailed,
        ContentStatus::PublishFailed,
    ];

    public function __construct(
        private readonly PublishSiloService $silos,
        private readonly PublishContentService $contents,
        private readonly PublishRedirectsService $redirects,
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

        // Launch gate: a present, non-compromised WordPress connection. needsRotation()
        // covers both compromised and never-rotated credentials.
        if (! $this->hasLaunchableConnection($site)) {
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

    private function hasLaunchableConnection(Site $site): bool
    {
        $connection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->first();

        return $connection !== null && ! $connection->needsRotation();
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
            ->whereIn('status', array_map(fn (ContentStatus $s) => $s->value, self::LAUNCHABLE))
            ->orderByRaw('kind = ?', ['post']) // pages (pillars) before posts
            ->orderBy('created_at')
            ->get();

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
