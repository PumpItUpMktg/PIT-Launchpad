<?php

use App\Integrations\Conversions\IngestConversions;
use App\Integrations\DataForSeo\IngestSerpTasks;
use App\Jobs\IngestCoverageScans;
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

// Portfolio-health counter reconcile — the drift net for the incremental Content/Connection observers.
// Bulk query updates and hard-delete prunes bypass model events, so a scheduled recompute-from-source
// keeps the /admin/sites counters honest. Idempotent; daily is cheap (a handful of COUNTs per site).
Schedule::command('launchpad:reconcile-site-counters')->daily()->withoutOverlapping();

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

// Published-board live-metrics warm — keep the GSC/index/position/GA4 caches populated for every
// engine-eligible site so an operator opens a board that's already warm, instead of a cold render
// deferring every card to "Refreshing…". Hourly (well under the vendor caches' TTL); withoutOverlapping.
// Also prunes any benign warm-cache failure a deploy/timeout left in failed_jobs.
Schedule::command('launchpad:warm-live-metrics')->hourly()->withoutOverlapping();

// GSC time-series snapshot — pull a trailing window of Search Console per
// connected site into the never-overwritten daily store (absorbing GSC's ~3-day
// revisions idempotently), then roll aged query-grain rows into the monthly
// table. Daily; withoutOverlapping so a slow multi-tenant pull can't stack. This
// is what stops the rolling 16-month window from erasing history.
Schedule::command('launchpad:sync-gsc')->daily()->withoutOverlapping();

// Index-coverage audit — run a Google URL Inspection for every published URL on
// each GSC-connected site so the Published cards read the REAL index verdict +
// crawl date from cache (rather than the impressions>0 proxy). Weekly: index
// status moves ~daily but the inspection is quota-limited (2,000/day/property),
// so a weekly platform-wide sweep keeps the cards fresh without burning quota;
// withoutOverlapping so a slow multi-tenant sweep can't stack. Ad-hoc single-site
// refresh is the "Refresh index coverage" button on the Corrections console.
Schedule::command('launchpad:audit-index')->weekly()->withoutOverlapping();

// Client-dashboard index spine — persist Google's per-URL verdicts into the
// durable page_index_states table + stamp the daily pages_indexed/pages_known
// snapshot the client dashboard trends. Shares the URL-Inspection cache with the
// weekly audit above, so daily runs spend quota only on stale/new URLs (and thus
// spread a large site's inspection across days). withoutOverlapping so a slow
// multi-tenant sweep can't stack.
Schedule::command('sandhog:sync-index')->daily()->withoutOverlapping();

// Operator GEO board — measure AI-search visibility (Claude web-search) for each site's active,
// operator-curated prompts into the durable geo_snapshots time-series. Weekly + budget-bounded +
// freshness-cached: each prompt is a web-search answer + a Haiku judge. withoutOverlapping so a slow
// multi-tenant sweep can't stack.
Schedule::command('sandhog:sync-geo')->weekly()->withoutOverlapping();

// Prune the GEO check activity log past its retention window so the append-only table stays bounded.
Schedule::command('sandhog:prune-geo-events')->weekly();

// Client-dashboard "Site speed" — measure Core Web Vitals (PageSpeed Insights) for each site's
// published pages into the durable page_vitals table. Weekly + budget-bounded + freshness-cached: one
// PSI call per URL is slow and quota-limited, so a weekly sweep fills coverage over time. withoutOverlapping
// so a slow multi-tenant sweep can't stack.
Schedule::command('sandhog:sync-vitals')->weekly()->withoutOverlapping();

// Client-dashboard rank spine — roll the §5 position-snapshot series up into the
// metric spine (per-keyword rank + site standings) so the dashboard trends keyword
// movement. Reads an existing store (no DataForSEO call), so it's cheap; daily with a
// short trailing window, idempotent. withoutOverlapping so a slow sweep can't stack.
Schedule::command('sandhog:sync-rankings')->daily()->withoutOverlapping();

// Client-dashboard milestones — derive the client's narrative beats (first indexed,
// first page-1 keyword, blog-volume) from the metric spine + page-index state. Runs
// after the day's syncs; cheap + read-only over the DB, idempotent per (site, key).
Schedule::command('sandhog:derive-milestones')->dailyAt('05:00')->withoutOverlapping();

// §7c client monthly report — email each client the prior month's keyword-
// improvement report (PDF attached) on the 1st. Defaults to last month so a
// complete month is reported; per-site opt-out is having no client users.
Schedule::command('launchpad:send-monthly-reports')->monthlyOn(1, '08:00')->withoutOverlapping();

// Places refresh — weekly re-pull of each GBP-backed location's Google Business Profile, so the cached
// Location and its GBP-tracked NAP fields never drift from the live GBP (operator overrides preserved by the
// hydrator). One Places details call per location/week (shared account); queues one job per location.
// withoutOverlapping so a slow multi-tenant sweep can't stack. Ad-hoc refresh is the "Import from Google"
// action on a location.
Schedule::command('launchpad:refresh-places --all')->weekly()->withoutOverlapping();

// Citation scan — monthly directory-listing sweep for every NAP-profiled location. Queues one scan per
// location; the run records what changed (new/fixed/regressed/lost), verifies in-flight submissions, and
// refreshes the Local Presence Score. withoutOverlapping so a slow month can't stack.
Schedule::command('launchpad:citation-scan --all')->monthlyOn(1, '06:00')->withoutOverlapping();

// Coverage scan scheduler — dispatch the queued town-scans for every per-GBP coverage plan whose cadence has
// come due, then advance its next run. Daily check; the plans carry the real cadence (monthly/weekly), so
// this just fires what's due today. Cost-braked per plan. withoutOverlapping so a slow sweep can't stack.
Schedule::command('launchpad:run-due-coverage-plans')->daily()->withoutOverlapping();

// Coverage-scan collection sweep — the async half of RunCoverageScan. Coverage scans are posted fast
// (pending) and their 100+ rate-limited town task_get calls are collected here in bounded batches, so a
// whole-county scan never overruns a job timeout. Finalizes each scan (complete, or partial past the expiry
// window) and recomputes its aggregates. Every five minutes; withoutOverlapping so runs can't double-collect.
Schedule::job(new IngestCoverageScans)->everyFiveMinutes()->withoutOverlapping();

// Review Capture reminders — day-3 / day-10 nudges for unsubmitted review requests (capped at 2, per-tenant
// toggle). Daily; the command itself decides which requests are due. Everything it sends is queued.
Schedule::command('launchpad:send-review-reminders')->dailyAt('09:20');
