<?php

use App\Integrations\DataForSeo\IngestSerpTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §9 staleness check — advisory rotation reminders for the admin connections
// panel. Never auto-rotates; the pre-client launch gate is the hard requirement.
Schedule::command('launchpad:check-stale-connections')->weekly();

// §5 standard-mode DataForSEO ingest sweep — polls tasks_ready and collects
// finished SERP/maps tasks into the cache the providers read (first-cut polling;
// postback is an optional later swap). withoutOverlapping keeps one sweep at a
// time so concurrent runs can't double-collect.
Schedule::job(new IngestSerpTasks)->everyFiveMinutes()->withoutOverlapping();
