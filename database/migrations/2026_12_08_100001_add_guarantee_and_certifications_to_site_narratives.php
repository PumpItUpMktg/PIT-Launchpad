<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two home-services trust signals, captured verbatim (never generated — a fabricated guarantee or a
 * made-up credential is false advertising): the tenant's guarantee/warranty ({name, description}) and
 * their certifications/credentials (a list of {label, number?, logo_url?}). Both optional; presence
 * drives whether the guarantee band / certifications row render. Live on SiteNarrative alongside the
 * other trust/narrative content, so they're reusable across home / About / Why-Choose-Us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_narratives', function (Blueprint $table): void {
            $table->json('guarantee')->nullable()->after('differentiators');
            $table->json('certifications')->nullable()->after('guarantee');
        });
    }

    public function down(): void
    {
        Schema::table('site_narratives', function (Blueprint $table): void {
            $table->dropColumn(['guarantee', 'certifications']);
        });
    }
};
