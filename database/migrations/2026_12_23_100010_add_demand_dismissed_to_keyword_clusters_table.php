<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            // Operator dismissed this cluster from the demand-without-service report (the demand still
            // exists in the derived tree; it just stops nagging as a "no service" finding).
            $table->boolean('demand_dismissed')->default(false)->after('dropped');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->dropColumn('demand_dismissed');
        });
    }
};
