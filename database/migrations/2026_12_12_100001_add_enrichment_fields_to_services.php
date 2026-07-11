<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub+spoke relay (§ service pages): post-intake enrichment on the §1 Service record — the
 * spoke-page anatomy's data source (symptoms / scope / process / cost / comparison), edited on the
 * new ServiceResource form after onboarding, same pattern as served_towns on locations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('short_description')->nullable()->after('description');
            $table->json('symptoms')->nullable()->after('short_description');
            $table->json('scope_items')->nullable()->after('symptoms');
            $table->json('process_steps')->nullable()->after('scope_items');
            $table->json('cost_factors')->nullable()->after('process_steps');
            $table->json('price_range')->nullable()->after('cost_factors');
            $table->json('comparison')->nullable()->after('price_range');
            $table->boolean('warranty_applicable')->default(false)->after('comparison');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn([
                'short_description', 'symptoms', 'scope_items', 'process_steps',
                'cost_factors', 'price_range', 'comparison', 'warranty_applicable',
            ]);
        });
    }
};
