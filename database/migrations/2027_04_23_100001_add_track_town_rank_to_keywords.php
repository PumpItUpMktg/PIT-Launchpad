<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town Rank: `track_town_rank` opts a keyword into the Town Rank card wall and the weekly town sweep — set
 * when an operator adds a keyword on the Town Rank page. Separate from `is_grid_keyword` (the geo-grid /
 * Maps opt-in, which costs 49 Maps requests per location per scan) so tracking the website's town rank never
 * silently enlists a keyword in the map-pack scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->boolean('track_town_rank')->default(false)->index()->after('is_grid_keyword');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropColumn('track_town_rank');
        });
    }
};
