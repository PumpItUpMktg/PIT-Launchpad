<?php

namespace App\Jobs;

use App\Activity\ActivityRecorder;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\IndexBooster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After a publish-drip release lands: link each newly-live page from a few of the site's highest-ranking
 * relevant indexed pages (its office hub, its silo pillar, sibling towns — Search-Console-ranked) and
 * re-push those sources once, so Google reaches the new pages through pages it already crawls. Dispatched
 * with a delay so the pushes have landed first; a page indexed in the meantime is skipped.
 */
class BoostReleasedPages implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** @param  list<string>  $contentIds */
    public function __construct(public readonly string $siteId, public readonly array $contentIds) {}

    public function handle(IndexBooster $booster, ActivityRecorder $activity): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null || $this->contentIds === []) {
            return;
        }
        $targets = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($this->contentIds)->get();
        $result = $booster->boostTargets($site, $targets);
        Log::info('Publish drip: inbound links added', ['site_id' => $site->id, 'links' => $result['links'], 'sources_repushed' => $result['sources_repushed']]);
        if ($result['links'] > 0) {
            $activity->record(
                (string) $site->id,
                ActivityRecorder::PUBLISH_RELEASED,
                sprintf('Linked %d new %s from %d ranking %s so Google finds them', count($result['details']), count($result['details']) === 1 ? 'page' : 'pages', $result['sources_repushed'], $result['sources_repushed'] === 1 ? 'page' : 'pages'),
                ['links' => $result['links'], 'pages' => count($result['details'])],
                clientVisible: true,
            );
        }
    }
}
