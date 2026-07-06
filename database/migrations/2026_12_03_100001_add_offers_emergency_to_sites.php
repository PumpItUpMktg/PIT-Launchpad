<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `offers_emergency` business flag (Gutenberg pivot, Layer 3). Data-gates the hero/CTA phone
 * treatment: ON → phone-forward with an urgent "24/7" hierarchy (call becomes the primary CTA); OFF →
 * the number stays prominent but framed calmly. Default FALSE so a non-emergency business (mold
 * testing, water conditioning) never carries a false "24/7" claim — it must be opted into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('offers_emergency')->default(false)->after('brand_name');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('offers_emergency');
        });
    }
};
