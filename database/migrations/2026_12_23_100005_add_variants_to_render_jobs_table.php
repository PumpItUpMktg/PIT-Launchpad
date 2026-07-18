<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            // Responsive downscale variants of the rendered image: { width => r2_key }. Populated at
            // render time (and by launchpad:backfill-image-variants for images rendered before this
            // shipped); the source render (r2_key + width) stays the largest srcset candidate. Null
            // when no variant was derived — the page then serves the single source image, no srcset.
            $table->json('variants')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->dropColumn('variants');
        });
    }
};
