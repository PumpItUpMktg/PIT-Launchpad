<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of competitor intersects per site (§ Links): which competitors were asked about and why some
 * were excluded, what the index returned and what the door kept — what the command prints, kept so the
 * Link prospects page can show it beside the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_prospect_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('complete');     // complete | failed
            $table->json('targets');                            // [{domain, appearances, source}]
            $table->json('excluded');                           // [{domain, appearances, share}]
            $table->unsignedInteger('returned')->default(0);
            $table->unsignedInteger('kept')->default(0);
            $table->unsignedInteger('new')->default(0);
            $table->unsignedInteger('refreshed')->default(0);
            $table->unsignedInteger('pruned')->default(0);
            $table->unsignedInteger('dropped_spam')->default(0);
            $table->unsignedInteger('dropped_rank')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['site_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_prospect_runs');
    }
};
