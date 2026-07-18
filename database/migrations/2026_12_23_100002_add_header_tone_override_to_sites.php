<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator override for the header background tone. The tone is normally derived from the uploaded logo
 * (LogoHeaderTone), defaulting to 'light'. This column lets an operator force 'light' or 'dark'
 * regardless of the logo — null means "auto" (use the logo-derived value). Read by
 * SiteProfileAssembler and pushed in the site profile the companion header renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('header_tone_override')->nullable()->after('brand_name');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('header_tone_override');
        });
    }
};
