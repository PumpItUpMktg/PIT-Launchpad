<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            // Operator override for the §7b target-queue ordering (promote/demote);
            // ties broken by the §5 opportunity_score. 0 = no override.
            $table->integer('priority')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
