<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a map-pack competitor sits — resolved once per place id through Places, shared by every
        // tenant (a place is a place). Lets a lost town split into "lost to a closer GBP" vs "to a farther one".
        Schema::create('competitor_places', function (Blueprint $table) {
            $table->string('place_id')->primary();
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('failed_at')->nullable();   // Places answered nothing usable; retried after a while
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_places');
    }
};
