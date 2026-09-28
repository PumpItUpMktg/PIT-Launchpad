<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `read_error` held a vendor / transport exception message in a 255-char column. An HTTP client failure
 * message carries the response excerpt and easily exceeds that, so recording ONE unreadable town threw a
 * PDO "value too long" and failed the whole minute's collection run (every minute, while that task stayed
 * unreadable). Text, so the reason is kept whole; the collectors bound it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['town_rank_points', 'geo_grid_points'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->text('read_error')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['town_rank_points', 'geo_grid_points'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('read_error')->nullable()->change();
            });
        }
    }
};
