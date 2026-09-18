<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant opt-in for the local air-quality card, exactly like `weather_alert` beside it.
 *
 * The card ships in the block theme as a template part, so without a flag it would appear on every
 * tenant's footer the moment the theme updates — including the ones it says nothing useful to. Default
 * off: the part renders nothing until an operator turns it on for that site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('air_card')->default(false)->after('weather_alert');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('air_card');
        });
    }
};
