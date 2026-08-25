<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator priority override (high|normal|low) — leads the check + content order ahead of the
     * automatic size-tier ranking, so a human can pin the questions that matter most to the front of a
     * budget-bounded run. Defaults 'normal' so existing prompts keep the size-tier order unchanged.
     */
    public function up(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->string('priority', 16)->default('normal')->after('size_tier');
        });
    }

    public function down(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
