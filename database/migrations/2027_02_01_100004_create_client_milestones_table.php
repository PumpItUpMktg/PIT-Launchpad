<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived, client-visible narrative beats (§ Client Dashboard v1): first_page_indexed, blog_post_10/50/100,
 * first_top10_keyword, etc. Milestones are derived from the metric spine + page index states, never entered
 * by hand. Keyed on (site_id, key) so re-derivation is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_milestones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('key');                          // first_page_indexed | blog_post_10 | first_top10_keyword …
            $table->date('occurred_on');
            $table->jsonb('payload')->nullable();           // supporting detail (url, keyword, count)
            $table->boolean('is_client_visible')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_milestones');
    }
};
