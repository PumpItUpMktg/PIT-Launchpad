<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The prompt lane: `visibility` (default — the neutral demand prompts that drive the cited% metric)
     * vs `coverage` (brand-anchored accuracy checks, reported separately). Defaults 'visibility' so every
     * existing prompt stays in the primary metric unchanged.
     */
    public function up(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->string('kind', 16)->default('visibility')->after('source');
            $table->index(['site_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
