<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town-page priority keywords: the operator picks up to three tracked keywords per site; each gets its own
 * drafted section (and two FAQ items) on every town page whose population earns it. The rank (1..3) is
 * the order they are handed out when a smaller town takes fewer than all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->unsignedTinyInteger('town_priority_rank')->nullable()->after('track_town_rank');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropColumn('town_priority_rank');
        });
    }
};
