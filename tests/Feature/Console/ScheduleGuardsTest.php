<?php

use App\Integrations\DataForSeo\IngestSerpTasks;
use App\Jobs\IngestCoverageScans;
use App\Jobs\IngestTownRankScans;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('runs every scheduled entry on one server only, so a second scheduler process cannot duplicate dispatches', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect($event->onOneServer)->toBeTrue("scheduled entry is not guarded: {$event->description}");
    }
});

it('makes the every-few-minutes collectors unique, so a duplicate dispatch is dropped rather than queued', function () {
    foreach ([IngestTownRankScans::class, IngestCoverageScans::class, IngestSerpTasks::class] as $job) {
        expect(new $job)->toBeInstanceOf(ShouldBeUnique::class);
    }
});
