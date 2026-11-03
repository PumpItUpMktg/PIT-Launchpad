<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold outreach pipeline: contacts imported from a cold-email database, verified with Bird's email
 * lookup, warmed in an Instantly campaign, and promoted into a Brevo list once they engage. One
 * settings row per site holds the campaign / list targets and the verify + warmth thresholds; the
 * API keys live on `connections` (encrypted), never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cold_outreach_configs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('instantly_campaign_id')->nullable();
            $table->unsignedBigInteger('brevo_list_id')->nullable();
            $table->unsignedTinyInteger('min_confidence')->default(70);
            $table->boolean('accept_neutral')->default(true);
            $table->boolean('warm_on_reply')->default(true);
            $table->boolean('warm_on_interested')->default(true);
            $table->unsignedSmallInteger('warm_min_opens')->default(0); // 0 = opens alone never warm a contact
            $table->boolean('auto_promote')->default(true);
            $table->timestamps();
        });

        Schema::create('cold_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('email_key'); // lower-cased, the dedupe key
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->json('custom')->nullable();
            $table->string('source')->nullable();
            $table->string('stage')->index();

            $table->string('verify_result')->nullable();
            $table->unsignedTinyInteger('verify_confidence')->nullable();
            $table->json('verify_flags')->nullable();
            $table->string('verify_reason')->nullable();
            $table->string('did_you_mean')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->string('instantly_lead_id')->nullable()->index();
            $table->string('instantly_campaign_id')->nullable();
            $table->timestamp('warming_started_at')->nullable();
            $table->integer('instantly_status')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->integer('interest_status')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('warm_at')->nullable();
            $table->string('warm_reason')->nullable();
            $table->string('disqualified_reason')->nullable();

            $table->string('brevo_contact_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'email_key']);
            $table->index(['site_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cold_contacts');
        Schema::dropIfExists('cold_outreach_configs');
    }
};
