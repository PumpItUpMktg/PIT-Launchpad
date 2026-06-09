<?php

use App\Integrations\Conversions\IngestConversions;
use App\Integrations\DataForSeo\IngestSerpTasks;
use App\KeywordGenerator\Pipeline\RefreshKeywordPipelines;
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

// §7c conversion ingest — per tenant, pull every active source (GA4 + Krayin +
// Mautic) and upsert dated-count Conversion rows the dashboard reads. Hourly;
// withoutOverlapping so a slow run can't stack.
Schedule::job(new IngestConversions)->hourly()->withoutOverlapping();

// §5 pipeline driver — runs keyword discovery + position tracking per engine-
// eligible site. Daily; the per-site cadence (off durable artifacts) gates the
// actual work, so tracking refreshes on its beat and discovery runs slower.
Schedule::job(new RefreshKeywordPipelines)->daily()->withoutOverlapping();

// §6a generated feeds — materialize the keyword map × markets into Google News
// feeds (idempotent; retires stale by deactivation). Daily, after the pipeline
// refresh so new keywords/markets project on the same beat.
Schedule::command('launchpad:reconcile-generated-feeds')->daily()->withoutOverlapping();

// §6a feed ingest — fetch every active feed (generated + client) and route items
// through the candidate funnel. Hourly; withoutOverlapping so the keyword×geo
// fan-out can't stack runs.
Schedule::command('launchpad:ingest-feeds')->hourly()->withoutOverlapping();
