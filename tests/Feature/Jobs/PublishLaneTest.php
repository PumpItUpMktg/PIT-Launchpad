<?php

use App\Jobs\PublishContent;
use App\Jobs\PublishJob;
use App\Jobs\PublishRedirects;
use App\Jobs\PublishSilo;
use App\Jobs\UnpublishJob;

function publishJobs(): array
{
    return [
        new PublishContent('c'), new PublishSilo('s'), new PublishRedirects('site'), new PublishJob('j'), new UnpublishJob('j'),
    ];
}

it('rides the default lane when no publish lane is configured — today\'s behaviour, unchanged', function () {
    config(['launchpad.publish.queue' => null]);

    foreach (publishJobs() as $job) {
        expect($job->queue)->toBeNull();
    }
});

it('routes every WordPress-push job to the configured publish lane', function () {
    config(['launchpad.publish.queue' => 'publish']);

    foreach (publishJobs() as $job) {
        expect($job->queue)->toBe('publish', $job::class);
    }
});

it('treats a blank setting as unset', function () {
    config(['launchpad.publish.queue' => '']);

    expect((new PublishContent('c'))->queue)->toBeNull();
});
