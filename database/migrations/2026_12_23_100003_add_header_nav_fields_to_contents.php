<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator control over the header main menu. `nav_featured` marks a page for the header nav;
 * `nav_order` is the manual sort within it (ascending, nulls last). When a site has ANY featured page
 * the header shows exactly those (in order, uncapped); with none featured it falls back to the
 * automatic importance-ranked top-8 of service/hub pages. Read by SiteProfileAssembler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->boolean('nav_featured')->default(false)->after('slug');
            $table->unsignedSmallInteger('nav_order')->nullable()->after('nav_featured');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['nav_featured', 'nav_order']);
        });
    }
};
