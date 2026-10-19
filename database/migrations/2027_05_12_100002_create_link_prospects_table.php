<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link prospects (§ Links): referring domains that link to the site's local competitors but not to the
 * site, one row per domain per site, found by the competitor intersect and worked by hand (status + notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_prospects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->unsignedSmallInteger('rank')->nullable();        // 0-100
            $table->unsignedSmallInteger('spam_score')->nullable();  // 0-100
            $table->json('competitors');                             // the competitor domains it links to
            $table->unsignedSmallInteger('competitor_count')->default(0);
            $table->string('kind')->default('local');                // LinkProspectKind
            $table->string('status')->default('new')->index();       // LinkProspectStatus
            $table->text('notes')->nullable();
            $table->date('first_seen')->nullable();                  // when the index first saw it link to a competitor
            $table->timestamp('found_at');                           // the last intersect that returned it
            $table->timestamps();

            $table->unique(['site_id', 'domain']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_prospects');
    }
};
