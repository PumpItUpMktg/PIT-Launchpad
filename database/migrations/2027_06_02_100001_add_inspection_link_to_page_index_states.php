<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google's own URL Inspection deep link (`inspectionResultLink` on the URL Inspection API response). Its
 * `id` is an opaque inspection token only the API hands out — a hand-built link with the page URL in it
 * is a Google 404 — so the link is kept with the verdict and the boards open that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->text('inspection_link')->nullable()->after('canonical_url');
        });
    }

    public function down(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->dropColumn('inspection_link');
        });
    }
};
