<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // §7c white-label: the client panel is branded per Account — Launchpad
            // is invisible, the client sees the agency's brand.
            $table->string('brand_name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('accent_color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['brand_name', 'logo_url', 'primary_color', 'accent_color']);
        });
    }
};
