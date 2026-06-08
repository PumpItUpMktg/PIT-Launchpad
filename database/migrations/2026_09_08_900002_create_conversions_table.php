<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // Leads/conversions as the revenue *proxy* — totals + trends only.
            // Deliberately NO attribution-to-action / ROI column: the dashboard
            // shows the data honestly and never fabricates per-action attribution.
            $table->string('type')->default('lead');
            $table->string('source')->default('manual');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['site_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
