<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * § Links — email outreach. One row per email approved and sent (or refused by the provider) to a link
 * prospect; the monthly cap counts these rows. The prospect carries the client's decision, the contact
 * found for it, and a running count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_emails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('link_prospect_id')->constrained()->cascadeOnDelete();
            $table->ulid('outreach_kit_id')->nullable();
            $table->ulid('location_id')->nullable();
            $table->ulid('sent_by')->nullable();                  // the user who clicked Approve & send
            $table->string('kind')->default('pitch');            // pitch | follow_up
            $table->string('to_email');
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('queued');         // queued | sent | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('approved_at');                    // counts against the month it was approved in
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'approved_at']);
            $table->index(['link_prospect_id', 'kind']);
        });

        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->string('client_decision')->nullable()->index();   // ClientDecision; null = undecided
            $table->timestamp('client_decided_at')->nullable();
            $table->ulid('client_decided_by')->nullable();
            $table->string('contact_source')->nullable();             // mailto | page | manual
            $table->json('contact_candidates')->nullable();           // every address the finder saw
            $table->timestamp('contact_checked_at')->nullable();
            $table->unsignedSmallInteger('emails_sent')->default(0);
            $table->timestamp('last_emailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('link_prospects', function (Blueprint $table): void {
            $table->dropColumn(['client_decision', 'client_decided_at', 'client_decided_by', 'contact_source', 'contact_candidates', 'contact_checked_at', 'emails_sent', 'last_emailed_at']);
        });
        Schema::dropIfExists('outreach_emails');
    }
};
