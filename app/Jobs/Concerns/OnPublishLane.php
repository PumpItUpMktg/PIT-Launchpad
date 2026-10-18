<?php

namespace App\Jobs\Concerns;

/**
 * Route a WordPress-push job to the publish lane when one is configured. Publishing is seconds of work
 * (assemble + one REST upsert); drafting is minutes (one model call, up to the request timeout and a
 * retry). On a single `default` lane they interleave first-in-first-out, so an operator who approves
 * twenty-six posts watches "queued to publish" sit still behind the drafts ahead of them. With
 * `LAUNCHPAD_PUBLISH_QUEUE` set (e.g. "publish") every push rides its own lane — the worker for that
 * lane must then be started (`queue:work --queue=publish`), or the Queue board shows it silent. Blank
 * keeps today's behaviour: everything on `default`.
 */
trait OnPublishLane
{
    protected function onPublishLane(): void
    {
        $queue = config('launchpad.publish.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }
}
