<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            // Dimension tags — what a prompt covers, so the coverage matrix can group by service × market
            // and a gap can map straight to a content target. Deferred-FK style (indexed, no constraint).
            $table->ulid('service_id')->nullable()->after('site_id');
            $table->ulid('market_id')->nullable()->after('service_id');
            $table->string('intent', 32)->nullable()->after('market_id');
            $table->string('source', 16)->default('manual')->after('intent');

            $table->index(['site_id', 'service_id']);
            $table->index(['site_id', 'market_id']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'service_id']);
            $table->dropIndex(['site_id', 'market_id']);
            $table->dropColumn(['service_id', 'market_id', 'intent', 'source']);
        });
    }
};
