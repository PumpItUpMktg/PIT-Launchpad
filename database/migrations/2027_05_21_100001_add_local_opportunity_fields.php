<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local link opportunities (§ Links): per-tenant inputs for the discovery pass and the outreach guardrails,
 * and the fields an opportunity prospect carries beyond a competitor-link prospect (the page it was found
 * on, the search that found it, the office it is local to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('brands')->nullable();                       // the brands the tenant installs / carries — one dealer-locator search each
            $table->json('link_lanes')->nullable();                   // the opportunity lanes to search (null = all)
            $table->unsignedInteger('sponsorship_budget_min')->nullable();  // dollars per year
            $table->unsignedInteger('sponsorship_budget_max')->nullable();
            $table->unsignedSmallInteger('outreach_monthly_cap')->default(20); // outreach emails per calendar month, hard ceiling
            $table->string('outreach_from_email')->nullable();        // on the tenant's own domain — the only sender ever used
            $table->string('outreach_from_name')->nullable();
        });

        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->string('url', 2048)->nullable();                  // the page the opportunity lives on (an opportunity prospect)
            $table->string('query')->nullable();                      // the search that surfaced it
            $table->ulid('location_id')->nullable()->index();         // the office it is local to (first match)
            $table->json('offices')->nullable();                      // every office whose searches surfaced it
            $table->unsignedTinyInteger('locality')->default(0);      // 0–3: how local the page reads
            $table->string('contact_email')->nullable();              // found on the page; the outreach address
        });

        Schema::table('link_prospect_runs', function (Blueprint $table): void {
            $table->string('scope')->default('competitors')->index(); // competitors | opportunities
            $table->unsignedInteger('queries')->default(0);           // searches spent (opportunities)
        });
    }

    public function down(): void
    {
        Schema::table('link_prospect_runs', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'queries']);
        });
        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->dropColumn(['url', 'query', 'location_id', 'offices', 'locality', 'contact_email']);
        });
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['brands', 'link_lanes', 'sponsorship_budget_min', 'sponsorship_budget_max', 'outreach_monthly_cap', 'outreach_from_email', 'outreach_from_name']);
        });
    }
};
