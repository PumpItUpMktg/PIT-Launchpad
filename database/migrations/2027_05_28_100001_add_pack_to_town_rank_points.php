<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town Rank: the LOCAL PACK lane. The same SERP read a point already stores carried the map-pack cards all
 * along (DataForSEO `local_pack` items: business name, website, rating) and the parser threw them away —
 * so a keyword Google showed the business for in 515 towns read "0 found" on the wall because every one
 * of those appearances was a pack card, not an organic link. A point now keeps the pack it saw
 * (`pack_results`: null = not measured, [] = no pack on that page) and the business's slot in it
 * (`pack_rank`: 1–3, null = not in it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('town_rank_points', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pack_rank')->nullable()->after('ranking_url');
            $table->json('pack_results')->nullable()->after('top_results');
        });
    }

    public function down(): void
    {
        Schema::table('town_rank_points', function (Blueprint $table): void {
            $table->dropColumn(['pack_rank', 'pack_results']);
        });
    }
};
