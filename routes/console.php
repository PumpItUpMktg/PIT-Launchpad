<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §9 staleness check — advisory rotation reminders for the admin connections
// panel. Never auto-rotates; the pre-client launch gate is the hard requirement.
Schedule::command('launchpad:check-stale-connections')->weekly();
